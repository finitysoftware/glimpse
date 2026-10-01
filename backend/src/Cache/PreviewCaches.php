<?php

declare(strict_types=1);

namespace Glimpse\Cache;

use Glimpse\Database\Connection;
use Glimpse\Database\Dates;
use Glimpse\Database\Transaction;
use Glimpse\Source\Provider;
use Glimpse\Source\SourceAccess;
use PDO;
use RuntimeException;

/**
 * The preview caches of all users: every locked write (for PreviewCache, one request's view) and maintenance.
 * Lock order is always: the user's api_cache_state rows (in key order, github before gitlab), then that user's api_cache rows,
 * then cache_capacity_state.
 */
final readonly class PreviewCaches
{
    public function __construct(private Connection $connection) {}

    /**
     * The locked path of PreviewCache::synchronize: moves the user's cache of one platform to this credential version when it is
     * older (discarding the old token's entries) and returns its revision, or NULL when a newer version already owns it.
     */
    public function claimGeneration(SourceAccess $access): ?int
    {
        $pdo = $this->connection->get();
        [$user, $provider, $version] = [$access->userId, $access->provider->value, $access->credentialVersion];
        $pdo->prepare('INSERT IGNORE INTO api_cache_state (user_id, provider, credential_version) VALUES (?, ?, ?)')
            ->execute([$user, $provider, $version]);
        return Transaction::run($pdo, function () use ($user, $provider, $version): ?int {
            $state = $this->lockState($user, $provider);
            if ($state === null) {
                throw new RuntimeException('Cache state is unavailable.');
            }
            if ($state['version'] < $version) {
                $this->reset($user, $provider, $version);
                $state['revision']++;
            }
            return $state['version'] > $version ? null : $state['revision'];
        });
    }

    /**
     * The locked write of PreviewCache::put. A request started before an invalidation or token replacement (an older $revision)
     * cannot repopulate the cache; the user's oldest entries of the platform make room, and a full shared budget skips caching.
     */
    public function store(SourceAccess $access, ?int $revision, CacheEntry $entry, int $globalBudget): void
    {
        $pdo = $this->connection->get();
        [$user, $provider] = [$access->userId, $access->provider->value];
        $key = $entry->key;
        $bytes = strlen($entry->payload);
        Transaction::run($pdo, function () use ($pdo, $access, $revision, $user, $provider, $key, $bytes, $entry, $globalBudget): void {
            $state = $this->lockState($user, $provider);
            // Requests started before an invalidation or token replacement cannot repopulate the cache afterwards.
            if ($state === null || $state['revision'] !== $revision || $state['version'] !== $access->credentialVersion) {
                return;
            }
            $this->delete('user_id = ? AND provider = ? AND (expires_at <= UTC_TIMESTAMP(6) OR cache_key = ?)', [$user, $provider, $key]);
            $totals = $this->totals($user, $provider);
            $evictions = 0;
            // Only this user's oldest entries of the same platform are evicted; other workspaces are never touched.
            while ($totals['entries'] >= PreviewCache::MAX_ENTRIES || $totals['bytes'] + $bytes > PreviewCache::MAX_BYTES) {
                $oldest = $pdo->prepare('SELECT cache_key FROM api_cache WHERE user_id = ? AND provider = ? ORDER BY created_at, cache_key LIMIT 1');
                $oldest->execute([$user, $provider]);
                $oldestKey = $oldest->fetchColumn();
                if ($oldestKey === false) {
                    break;
                }
                $this->delete('user_id = ? AND provider = ? AND cache_key = ?', [$user, $provider, (string) $oldestKey]);
                $totals = $this->totals($user, $provider);
                $evictions++;
            }
            $capacity = $pdo->query('SELECT reserved_bytes FROM cache_capacity_state WHERE id = 1 FOR UPDATE');
            $reserved = $capacity === false ? false : $capacity->fetchColumn();
            // A full shared budget returns the response uncached instead of evicting another workspace.
            if ($reserved !== false && (int) $reserved + $bytes <= $globalBudget) {
                $pdo->prepare('INSERT INTO api_cache (cache_key, user_id, provider, credential_version, repository_id, scope_key, etag, payload, payload_bytes, created_at, expires_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))')
                    ->execute([$key, $user, $provider, $access->credentialVersion, $entry->repositoryId, $entry->scope, $entry->etag, $entry->payload, $bytes, min(3600, max(1, $entry->ttl))]);
                $pdo->prepare('UPDATE cache_capacity_state SET reserved_bytes = reserved_bytes + ?, reserved_entries = reserved_entries + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = 1')
                    ->execute([$bytes]);
            }
            if ($evictions > 0) {
                $pdo->prepare('UPDATE api_cache_state SET evictions = evictions + ? WHERE user_id = ? AND provider = ?')->execute([$evictions, $user, $provider]);
            }
        });
    }

    /** Removes one user's entries for a repository scope, e.g. after sharing is disabled or the platform denies access. */
    public function invalidate(int $userId, Provider $provider, string $scope): void
    {
        $pdo = $this->connection->get();
        $this->transaction(function () use ($pdo, $userId, $provider, $scope): void {
            if ($this->lockState($userId, $provider->value) === null) {
                return;
            }
            $pdo->prepare('UPDATE api_cache_state SET revision = revision + 1 WHERE user_id = ? AND provider = ?')->execute([$userId, $provider->value]);
            $this->delete('user_id = ? AND provider = ? AND scope_key = ?', [$userId, $provider->value, $scope]);
        });
    }

    /** Removes all of one user's entries of one platform (e.g. after a 401 for its token), or of every platform when NULL. Counters are kept. */
    public function invalidateUser(int $userId, ?Provider $provider = null): void
    {
        $pdo = $this->connection->get();
        $this->transaction(function () use ($pdo, $userId, $provider): void {
            $condition = $provider === null ? 'user_id = ?' : 'user_id = ? AND provider = ?';
            $parameters = $provider === null ? [$userId] : [$userId, $provider->value];
            $locked = $pdo->prepare('SELECT provider FROM api_cache_state WHERE ' . $condition . ' ORDER BY provider FOR UPDATE');
            $locked->execute($parameters);
            if ($locked->fetchAll() === []) {
                return;
            }
            $pdo->prepare('UPDATE api_cache_state SET revision = revision + 1 WHERE ' . $condition)->execute($parameters);
            $this->delete($condition, $parameters);
        });
    }

    /** Starts a new credential generation for one platform: its entries, counters and the last known quota of the old token are discarded. */
    public function resetUser(int $userId, Provider $provider, int $credentialVersion): void
    {
        $pdo = $this->connection->get();
        $this->transaction(function () use ($pdo, $userId, $provider, $credentialVersion): void {
            $pdo->prepare('INSERT IGNORE INTO api_cache_state (user_id, provider, credential_version) VALUES (?, ?, ?)')->execute([$userId, $provider->value, $credentialVersion]);
            $this->lockState($userId, $provider->value);
            $this->reset($userId, $provider->value, $credentialVersion);
        });
    }

    /** The last credential generation of one platform; a new connection continues after it, so no cache generation is ever reused. */
    public function generation(int $userId, Provider $provider): int
    {
        $read = $this->connection->get()->prepare('SELECT credential_version FROM api_cache_state WHERE user_id = ? AND provider = ?');
        $read->execute([$userId, $provider->value]);
        return (int) $read->fetchColumn();
    }

    /** Separate maintenance: removes expired entries user by user and recalculates the shared budget.
     * @return int Removed entries.
     */
    public function purgeExpired(): int
    {
        $pdo = $this->connection->get();
        $removed = 0;
        $owners = $pdo->query('SELECT DISTINCT user_id, provider FROM api_cache WHERE expires_at <= UTC_TIMESTAMP(6)');
        foreach ($owners === false ? [] : $owners->fetchAll(PDO::FETCH_ASSOC) as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            [$userId, $provider] = [(int) $owner['user_id'], (string) $owner['provider']];
            $this->transaction(function () use ($userId, $provider, &$removed): void {
                if ($this->lockState($userId, $provider) === null) {
                    return;
                }
                $removed += $this->delete('user_id = ? AND provider = ? AND expires_at <= UTC_TIMESTAMP(6)', [$userId, $provider]);
            });
        }
        $this->transaction(static function () use ($pdo): void {
            // Holding the capacity row serializes this with writers, whose inserts and counter updates commit together.
            $lock = $pdo->query('SELECT id FROM cache_capacity_state WHERE id = 1 FOR UPDATE');
            if ($lock !== false) {
                $lock->fetchAll();
            }
            $pdo->exec('UPDATE cache_capacity_state c, (SELECT COUNT(*) AS entries, COALESCE(SUM(payload_bytes), 0) AS bytes FROM api_cache) t
                SET c.reserved_bytes = t.bytes, c.reserved_entries = t.entries, c.updated_at = UTC_TIMESTAMP(6) WHERE c.id = 1');
        });
        return $removed;
    }

    /** One workspace's cache: its own entries and counters over all platforms, plus the last known quota of each platform's token. */
    public function status(int $userId): CacheStatus
    {
        $pdo = $this->connection->get();
        $totals = $this->totals($userId);
        $read = $pdo->prepare('SELECT COALESCE(SUM(hits), 0) AS hits, COALESCE(SUM(misses), 0) AS misses, COALESCE(SUM(upstream_calls), 0) AS upstream_calls,
            COALESCE(SUM(revalidations), 0) AS revalidations, COALESCE(SUM(evictions), 0) AS evictions FROM api_cache_state WHERE user_id = ?');
        $read->execute([$userId]);
        $counters = $read->fetch(PDO::FETCH_ASSOC);
        $counters = is_array($counters) ? $counters : [];
        $int = static fn(string $name): int => (int) ($counters[$name] ?? 0);
        $quota = $pdo->prepare('SELECT provider, rate_limit, rate_remaining, rate_reset, rate_checked_at FROM api_cache_state WHERE user_id = ?');
        $quota->execute([$userId]);
        $providers = [];
        $used = 0;
        foreach (Provider::cases() as $provider) {
            $providers[$provider->value] = new PlatformQuota(null, null, null, null);
        }
        foreach ($quota->fetchAll(PDO::FETCH_ASSOC) as $state) {
            if (!is_array($state) || !isset($providers[(string) $state['provider']])) {
                continue;
            }
            $used++;
            $nullable = static fn(string $name): ?int => $state[$name] === null ? null : (int) $state[$name];
            $providers[(string) $state['provider']] = new PlatformQuota(
                $nullable('rate_limit'),
                $nullable('rate_remaining'),
                $nullable('rate_reset'),
                Dates::isoOrNull($state['rate_checked_at']),
            );
        }
        // The limits apply per platform, so a user with both connections may use twice the space.
        $platforms = max(1, $used);
        return new CacheStatus(
            $totals['entries'],
            $totals['bytes'],
            PreviewCache::MAX_BYTES * $platforms,
            PreviewCache::MAX_ENTRIES * $platforms,
            $int('hits'),
            $int('misses'),
            $int('upstream_calls'),
            $int('revalidations'),
            $int('evictions'),
            $providers,
        );
    }

    /** @return array{version: int, revision: int}|null */
    private function lockState(int $userId, string $provider): ?array
    {
        $read = $this->connection->get()->prepare('SELECT credential_version, revision FROM api_cache_state WHERE user_id = ? AND provider = ? FOR UPDATE');
        $read->execute([$userId, $provider]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['version' => (int) $row['credential_version'], 'revision' => (int) $row['revision']] : null;
    }

    private function reset(int $userId, string $provider, int $credentialVersion): void
    {
        $this->delete('user_id = ? AND provider = ?', [$userId, $provider]);
        $this->connection->get()->prepare('UPDATE api_cache_state SET credential_version = ?, revision = revision + 1, hits = 0, misses = 0, upstream_calls = 0,
            revalidations = 0, evictions = 0, rate_limit = NULL, rate_remaining = NULL, rate_reset = NULL, rate_checked_at = NULL,
            blocked_until = NULL WHERE user_id = ? AND provider = ?')->execute([$credentialVersion, $userId, $provider]);
    }

    /** Deletes entries and releases their share of the global budget. The caller holds the owner's state rows. The condition is internal SQL, never request data.
     * @param list<int|string> $parameters
     */
    private function delete(string $condition, array $parameters): int
    {
        $pdo = $this->connection->get();
        $read = $pdo->prepare('SELECT COUNT(*) AS entries, COALESCE(SUM(payload_bytes), 0) AS bytes FROM api_cache WHERE ' . $condition);
        $read->execute($parameters);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) $row['entries'] === 0) {
            return 0;
        }
        $entries = (int) $row['entries'];
        $pdo->prepare('DELETE FROM api_cache WHERE ' . $condition)->execute($parameters);
        $pdo->prepare('UPDATE cache_capacity_state SET reserved_bytes = GREATEST(0, CAST(reserved_bytes AS SIGNED) - ?),
            reserved_entries = GREATEST(0, CAST(reserved_entries AS SIGNED) - ?), updated_at = UTC_TIMESTAMP(6) WHERE id = 1')
            ->execute([(int) $row['bytes'], $entries]);
        return $entries;
    }

    /** One user's entries of one platform, or of all platforms when $provider is NULL.
     * @return array{entries: int, bytes: int}
     */
    private function totals(int $userId, ?string $provider = null): array
    {
        $read = $this->connection->get()->prepare('SELECT COUNT(*) AS entries, COALESCE(SUM(payload_bytes), 0) AS bytes FROM api_cache WHERE user_id = ?'
            . ($provider === null ? '' : ' AND provider = ?'));
        $read->execute($provider === null ? [$userId] : [$userId, $provider]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        return ['entries' => is_array($row) ? (int) $row['entries'] : 0, 'bytes' => is_array($row) ? (int) $row['bytes'] : 0];
    }

    private function transaction(callable $work): void
    {
        $pdo = $this->connection->get();
        // Invalidation can run inside the transaction of the service that caused it; it then joins that one.
        if ($pdo->inTransaction()) {
            $work();
            return;
        }
        Transaction::run($pdo, $work);
    }
}
