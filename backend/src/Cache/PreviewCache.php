<?php

declare(strict_types=1);

namespace Glimpse\Cache;

use Glimpse\Database\Connection;
use Glimpse\Database\Dates;
use Glimpse\Failure;
use Glimpse\Source\HttpResult;
use Glimpse\Source\Provider;
use Glimpse\Source\RateLimit;
use Glimpse\Source\SourceAccess;
use PDO;
use PDOException;

/** One request's view of a per-user, per-platform preview cache. Entries, counters, quota and limits belong to one workspace's
 * connection to one platform; a shared byte budget protects the host. Locked writes go through PreviewCaches.
 */
final class PreviewCache
{
    public const MAX_BYTES = 33554432;
    public const MAX_ENTRIES = 256;
    public const MAX_ENTRY_BYTES = 524288;
    public const GLOBAL_MAX_BYTES = 268435456;
    private ?int $revision = null;
    private bool $stale = false;
    private readonly int $userId;
    private readonly string $provider;

    public function __construct(
        private readonly Connection $connection,
        private readonly SourceAccess $access,
        private readonly PreviewCaches $caches,
        private readonly int $globalBudget = self::GLOBAL_MAX_BYTES,
    ) {
        $this->userId = $access->userId;
        $this->provider = $access->provider->value;
    }

    /** One repository of one platform: `owner/name` on GitHub, the project ID on GitLab. */
    public static function scope(Provider $provider, string $repository): string
    {
        return hash('sha256', $provider->value . "\0" . strtolower($repository), true);
    }

    /** Keys include the platform, owner and credential version, so users, platforms and replaced tokens never share entries. */
    public function key(string $path, ?int $repositoryId): string
    {
        return hash('sha256', json_encode(['preview-v3', $this->provider, $this->userId, $this->access->credentialVersion, $repositoryId, $path], JSON_THROW_ON_ERROR), true);
    }

    /** Binds this request to the user's cache generation. A request for an older credential version becomes read/write-inert. */
    public function synchronize(): void
    {
        if ($this->revision !== null || $this->stale) {
            return;
        }
        $pdo = $this->connection->get();
        // Usually only reads: many visitors of one owner must not queue on the owner's state row. put() and the counters
        // check this revision again, so an invalidation right after this read still leaves the request inert.
        $read = $pdo->prepare('SELECT credential_version, revision FROM api_cache_state WHERE user_id = ? AND provider = ?');
        $read->execute([$this->userId, $this->provider]);
        $current = $read->fetch(PDO::FETCH_ASSOC);
        if (is_array($current) && (int) $current['credential_version'] >= $this->access->credentialVersion) {
            if ((int) $current['credential_version'] > $this->access->credentialVersion) {
                $this->stale = true;
            } else {
                $this->revision = (int) $current['revision'];
            }
            return;
        }
        // A missing row or a newer token needs the locked path, which also resets the cache of the old token.
        $revision = $this->caches->claimGeneration($this->access);
        if ($revision === null) {
            $this->stale = true;
        } else {
            $this->revision = $revision;
        }
    }

    /** @return array{response: HttpResult, fresh: bool, checkedAt: string}|null */
    public function get(string $key): ?array
    {
        $this->synchronize();
        if ($this->stale) {
            return null;
        }
        $read = $this->connection->get()->prepare('SELECT payload, created_at, expires_at > UTC_TIMESTAMP(6) AS fresh FROM api_cache WHERE cache_key = ? AND user_id = ? AND provider = ?');
        $read->execute([$key, $this->userId, $this->provider]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $data = json_decode((string) $row['payload'], true);
        if (!is_array($data) || !is_string($data['body'] ?? null) || !is_array($data['headers'] ?? null)) {
            return null;
        }
        $headers = [];
        foreach ($data['headers'] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }
        return ['response' => new HttpResult(200, $headers, $data['body']), 'fresh' => (bool) $row['fresh'],
            'checkedAt' => Dates::iso((string) $row['created_at'])];
    }

    public function put(string $key, string $scope, ?int $repositoryId, HttpResult $response, int $ttl): void
    {
        $this->synchronize();
        if ($this->stale) {
            return;
        }
        if (strlen($response->body) > self::MAX_ENTRY_BYTES || !mb_check_encoding($response->body, 'UTF-8')) {
            return;
        }
        $headers = array_intersect_key($response->headers, array_flip(['etag', 'link']));
        if (isset($headers['etag']) && (strlen($headers['etag']) > 255 || preg_match('/[\x00-\x1f\x7f]/', $headers['etag']))) {
            unset($headers['etag']);
        }
        $payload = json_encode(['headers' => $headers, 'body' => $response->body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $bytes = strlen($payload);
        if ($bytes > self::MAX_ENTRY_BYTES) {
            return;
        }
        $this->caches->store($this->access, $this->revision, new CacheEntry($key, $scope, $repositoryId, $headers['etag'] ?? null, $payload, $ttl), $this->globalBudget);
    }

    public function count(string $counter): void
    {
        if ($this->stale || !in_array($counter, ['hits', 'misses', 'upstream_calls', 'revalidations'], true)) {
            return;
        }
        try {
            $this->connection->get()->prepare('UPDATE api_cache_state SET ' . $counter . ' = ' . $counter . ' + 1 WHERE user_id = ? AND provider = ? AND revision = ?')
                ->execute([$this->userId, $this->provider, $this->revision]);
        } catch (PDOException $error) {
            // Statistics never fail a preview: under many parallel visitors a busy counter row is skipped
            // (1205 lock wait timeout, 1213 deadlock); any other database error still surfaces.
            if (!in_array($error->errorInfo[1] ?? null, [1205, 1213], true)) {
                throw $error;
            }
        }
    }

    /** The quota cooldown belongs to the user's token on one platform, not to the installation. */
    public function checkCooldown(): void
    {
        $this->synchronize();
        $read = $this->connection->get()->prepare('SELECT GREATEST(0, CEIL(TIMESTAMPDIFF(MICROSECOND, UTC_TIMESTAMP(6), blocked_until) / 1000000)) FROM api_cache_state
            WHERE user_id = ? AND provider = ? AND credential_version = ?');
        $read->execute([$this->userId, $this->provider, $this->access->credentialVersion]);
        $seconds = (int) $read->fetchColumn();
        if ($seconds > 0) {
            throw Failure::rateLimited($this->access->provider->code('rate_limited'), $this->access->provider->label() . ' request quota is temporarily unavailable.', $seconds);
        }
    }

    public function observe(HttpResult $response, ?int $cooldown): void
    {
        if ($this->stale) {
            return;
        }
        $quota = RateLimit::quota($response);
        $pdo = $this->connection->get();
        if ($quota['remaining'] !== null) {
            $pdo->prepare('UPDATE api_cache_state SET rate_limit = ?, rate_remaining = ?, rate_reset = ?, rate_checked_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND provider = ? AND revision = ?')
                ->execute([$quota['limit'], $quota['remaining'], $quota['reset'], $this->userId, $this->provider, $this->revision]);
        }
        if ($cooldown !== null) {
            $pdo->prepare('UPDATE api_cache_state SET blocked_until = GREATEST(COALESCE(blocked_until, UTC_TIMESTAMP(6)), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))
            WHERE user_id = ? AND provider = ? AND revision = ?')->execute([$cooldown, $this->userId, $this->provider, $this->revision]);
        }
    }

}
