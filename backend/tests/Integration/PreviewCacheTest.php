<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Cache\CachedTransport;
use Glimpse\Cache\PreviewCache;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Database\Connection;
use Glimpse\Failure;
use Glimpse\GitHub\GitHubCachePolicy;
use Glimpse\GitHub\GitHubClient;
use Glimpse\GitLab\GitLabCachePolicy;
use Glimpse\GitLab\GitLabClient;
use Glimpse\Repositories\BrowseQuery;
use Glimpse\Repositories\GitHubPlatform;
use Glimpse\Repositories\Preview;
use Glimpse\Repositories\RepositoryRef;
use Glimpse\Repositories\RepositoryService;
use Glimpse\Services;
use Glimpse\Settings;
use Glimpse\Source\HttpResult;
use Glimpse\Source\Provider;
use Glimpse\Source\SourceAccess;
use Glimpse\Source\Transport;
use Glimpse\Tests\Fixtures\FakeTransport;
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\GitLabRepositoryTransport;
use Glimpse\Tests\Fixtures\RepositoryTransport;
use Glimpse\Tests\Fixtures\Workspace;
use PDO;
use PHPUnit\Framework\TestCase;

final class PreviewCacheTest extends TestCase
{
    private Settings $settings;
    private Connection $connection;
    private PDO $pdo;
    private RepositoryTransport $upstream;
    private int $userId;

    protected function setUp(): void
    {
        $dsn = getenv('GLIMPSE_TEST_DB_DSN');
        if (!$dsn) {
            self::markTestSkipped('A dedicated MySQL/MariaDB test database is required.');
        }
        if (getenv('GLIMPSE_TEST_DB_RESET') !== '1' || !preg_match('/dbname=glimpse_test(?:;|$)/', $dsn)) {
            self::fail('Refusing to reset a non-test database.');
        }
        $this->settings = new Settings(environment: 'test', database: ['dsn' => $dsn,
            'username' => getenv('GLIMPSE_TEST_DB_USER') ?: 'root', 'password' => getenv('GLIMPSE_TEST_DB_PASSWORD') ?: '']);
        $this->connection = new Connection($this->settings);
        $this->pdo = $this->connection->get();
        Workspace::migrate($this->settings);
        Workspace::reset($this->pdo);
        $this->userId = Workspace::user($this->pdo);
        $this->upstream = new RepositoryTransport();
    }

    private function access(Transport $upstream, int $version = 1, ?int $userId = null): SourceAccess
    {
        return new SourceAccess(Provider::GitHub, $userId ?? $this->userId, $version, $upstream, Provider::GITHUB_INSTANCE, new GitHubCachePolicy());
    }
    /** @param array<string, string> $query */
    private function browse(array $query): Preview
    {
        $preview = $this->service()->browse(RepositoryRef::github('fixture', 'alpha'), BrowseQuery::fromQuery($query));
        self::assertInstanceOf(Preview::class, $preview);
        return $preview;
    }

    private function service(): RepositoryService
    {
        $connection = new Connection($this->settings);
        $github = (new Services($this->settings, github: new FixedTransports($this->upstream), connection: $connection))->github;
        return new RepositoryService($connection, $this->access($this->upstream), new GitHubPlatform($github), new PreviewCaches($connection));
    }
    private function scope(): string
    {
        return PreviewCache::scope(Provider::GitHub, 'fixture/alpha');
    }
    private function cache(): PreviewCache
    {
        return new PreviewCache($this->connection, $this->access($this->upstream), new PreviewCaches($this->connection));
    }
    private function connectionStatus(int $userId): string
    {
        $read = $this->pdo->prepare('SELECT last_check_status FROM github_connections WHERE user_id = ?');
        $read->execute([$userId]);
        return (string) $read->fetchColumn();
    }

    public function testHistoryAndCommitPagesShareImmutableCacheAndRefreshPinsNewHead(): void
    {
        $history = ['kind' => 'history', 'ref' => 'feature/history'];
        $first = $this->browse($history);
        self::assertSame(2, $first->history['nextPage'] ?? null);
        $calls = count($this->upstream->paths);
        self::assertSame('cache', $this->browse($history)->contentSource);
        self::assertCount($calls, $this->upstream->paths);
        $this->browse(['kind' => 'commit', 'sha' => RepositoryTransport::SHA]);
        $this->browse(['kind' => 'commit', 'sha' => RepositoryTransport::SHA, 'page' => '2']);
        self::assertCount($calls + 2, $this->upstream->paths);
        self::assertSame('cache', $this->browse(['kind' => 'commit', 'sha' => RepositoryTransport::SHA])->contentSource);
        self::assertCount($calls + 2, $this->upstream->paths);
        $this->upstream->head = str_repeat('e', 40);
        $updated = $this->browse($history + ['refresh' => '1']);
        self::assertSame($this->upstream->head, $updated->commitSha);
        self::assertSame('cache', $this->browse($history + ['sha' => RepositoryTransport::SHA])->contentSource);
        self::assertSame(RepositoryTransport::SHA, $first->history['items'][0]['sha'] ?? null);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_leases'));
    }

    public function testFailedPermissionCheckBlocksCachedHistoryAndDiff(): void
    {
        foreach (['history', 'commit'] as $kind) {
            $this->browse(['kind' => $kind, 'sha' => RepositoryTransport::SHA]);
        }
        $this->upstream->status = 403;
        foreach (['history', 'commit'] as $kind) {
            try {
                $this->browse(['kind' => $kind, 'sha' => RepositoryTransport::SHA, 'refresh' => '1']);
                self::fail('Denied history returned.');
            } catch (Failure $error) {
                self::assertSame('github_access_denied', $error->errorCode);
            }
        }
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_cache'));
    }

    /** @phpstan-impure */
    private function number(string $sql): int
    {
        $read = $this->pdo->prepare($sql);
        $read->execute();
        return (int) $read->fetchColumn();
    }

    public function testRepeatedPreviewUsesCacheAndRefreshOnlyDownloadsContentForANewSha(): void
    {
        $query = ['kind' => 'file', 'path' => 'README.md'];
        $first = $this->browse($query);
        self::assertCount(3, $this->upstream->paths);
        self::assertSame('github', $first->contentSource);
        $cached = $this->browse($query);
        self::assertSame('cache', $cached->contentSource);
        self::assertSame($first->file, $cached->file);
        self::assertCount(3, $this->upstream->paths);
        $this->browse($query + ['refresh' => '1']);
        self::assertCount(5, $this->upstream->paths);
        $this->upstream->head = str_repeat('b', 40);
        self::assertSame(RepositoryTransport::SHA, $this->browse($query)->commitSha);
        self::assertSame(str_repeat('b', 40), $this->browse($query + ['refresh' => '1'])->commitSha);
        self::assertCount(8, $this->upstream->paths);
        self::assertSame('cache', $this->browse($query + ['sha' => RepositoryTransport::SHA])->contentSource);
        self::assertCount(8, $this->upstream->paths);
        self::assertLessThanOrEqual(30, $this->number('SELECT MAX(TIMESTAMPDIFF(SECOND, created_at, expires_at)) FROM api_cache WHERE repository_id IS NULL AND payload NOT LIKE \'%base64%\''));
    }

    public function testExpiredPermissionCheckFailsClosedBeforeReturningCachedFile(): void
    {
        $query = ['kind' => 'file', 'path' => 'README.md'];
        $this->browse($query);
        $this->upstream->status = 403;
        self::assertSame('cache', $this->browse($query)->contentSource);
        $key = $this->cache()->key('/repos/fixture/alpha', null);
        $this->pdo->prepare('UPDATE api_cache SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE cache_key = ?')->execute([$key]);
        try {
            $this->browse($query);
            self::fail('Expired permission cache was accepted.');
        } catch (Failure $error) {
            self::assertSame('github_access_denied', $error->errorCode);
        }
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_cache'));
        self::assertCount(4, $this->upstream->paths);
    }

    public function testExpiredBranchMappingReadsNewHeadAndNeverSubstitutesADeletedFile(): void
    {
        $query = ['kind' => 'file', 'path' => 'README.md'];
        $this->browse($query);
        $this->upstream->head = str_repeat('c', 40);
        $this->upstream->missingFiles = [$this->upstream->head . ':README.md'];
        $key = $this->cache()->key('/repos/fixture/alpha/commits/main', 101);
        $this->pdo->prepare('UPDATE api_cache SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE cache_key = ?')->execute([$key]);
        try {
            $this->browse($query);
            self::fail('Deleted file was replaced with cached content.');
        } catch (Failure $error) {
            self::assertSame('github_not_found', $error->errorCode);
        }
        self::assertSame('/repos/fixture/alpha/contents/README.md?ref=' . $this->upstream->head, end($this->upstream->paths));
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_cache'));
    }

    public function testUpstreamMaintenanceRetryAfterIsPreservedWithoutAnAutomaticRetry(): void
    {
        $upstream = new FakeTransport([new HttpResult(503, ['retry-after' => '20'], '')]);
        try {
            (new GitHubClient(new CachedTransport($this->connection, $this->access($upstream), new PreviewCaches($this->connection), $this->scope())))->metadata('fixture', 'alpha');
            self::fail('Expected maintenance failure.');
        } catch (Failure $error) {
            self::assertSame('github_unavailable', $error->errorCode);
            self::assertSame(20, $error->retryAfter);
        }
        self::assertCount(1, $upstream->paths);
    }

    public function testKnownPermissionFailureOnRefreshInvalidatesStillFreshEntries(): void
    {
        $this->browse([]);
        $this->upstream->status = 404;
        try {
            $this->browse(['refresh' => '1']);
            self::fail('Expected access failure.');
        } catch (Failure $error) {
            self::assertSame('github_not_found', $error->errorCode);
        }
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_cache'));
    }

    public function testReplacedCredentialsClearOnlyThatUserAndAnOlderRequestCannotWriteItBack(): void
    {
        $other = Workspace::user($this->pdo, 'other');
        $otherCache = new PreviewCache($this->connection, $this->access($this->upstream, 1, $other), new PreviewCaches($this->connection));
        $otherCache->put($otherCache->key('kept', 1), $this->scope(), null, new HttpResult(200, [], '{}'), 3600);
        $this->browse([]);
        $older = $this->cache();
        $older->synchronize();
        // What saving a new token does for this user.
        (new PreviewCaches(new Connection($this->settings, $this->pdo)))->invalidateUser($this->userId);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_cache WHERE user_id = ' . $this->userId));
        $older->put($older->key('old-result', 1), $this->scope(), null, new HttpResult(200, [], '{}'), 3600);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_cache WHERE user_id = ' . $this->userId));
        self::assertNotNull($otherCache->get($otherCache->key('kept', 1)));
        $replacement = new PreviewCache($this->connection, $this->access($this->upstream, 2), new PreviewCaches($this->connection));
        self::assertNotSame($replacement->key('same-path', 1), $older->key('same-path', 1));
        self::assertNotSame($otherCache->key('same-path', 1), $older->key('same-path', 1));
    }

    public function testPlatformsOfOneUserKeepSeparateEntriesGenerationsAndCooldowns(): void
    {
        $github = $this->cache();
        $gitlab = new PreviewCache($this->connection, new SourceAccess(Provider::GitLab, $this->userId, 5, new FakeTransport([]), 'https://gitlab.example', new GitLabCachePolicy()), new PreviewCaches($this->connection));
        $gitlabScope = PreviewCache::scope(Provider::GitLab, '42');
        self::assertNotSame($this->scope(), PreviewCache::scope(Provider::GitLab, 'fixture/alpha'));
        self::assertNotSame($github->key('same-path', 1), $gitlab->key('same-path', 1));
        $github->put($github->key('same-path', 1), $this->scope(), null, new HttpResult(200, [], '{"from":"github"}'), 3600);
        $gitlab->put($gitlab->key('same-path', 1), $gitlabScope, null, new HttpResult(200, [], '{"from":"gitlab"}'), 3600);
        self::assertSame('{"from":"gitlab"}', $gitlab->get($gitlab->key('same-path', 1))['response']->body ?? null);
        self::assertSame(2, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->entries);

        // A new GitLab token starts a GitLab generation only; GitHub entries stay.
        (new PreviewCaches(new Connection($this->settings, $this->pdo)))->resetUser($this->userId, Provider::GitLab, 6);
        self::assertNull($gitlab->get($gitlab->key('same-path', 1)));
        self::assertNotNull($github->get($github->key('same-path', 1)));
        $newer = new PreviewCache($this->connection, new SourceAccess(Provider::GitLab, $this->userId, 6, new FakeTransport([]), 'https://gitlab.example', new GitLabCachePolicy()), new PreviewCaches($this->connection));
        $newer->put($newer->key('fresh', 1), $gitlabScope, null, new HttpResult(200, [], '{}'), 3600);
        // A late write of the replaced GitLab token is ignored.
        $gitlab->put($gitlab->key('late', 1), $gitlabScope, null, new HttpResult(200, [], '{}'), 3600);
        self::assertNull($gitlab->get($gitlab->key('late', 1)));
        $state = $this->pdo->query('SELECT provider, credential_version FROM api_cache_state WHERE user_id = ' . $this->userId . ' ORDER BY provider');
        self::assertNotFalse($state);
        self::assertSame(
            [['provider' => 'github', 'credential_version' => 1], ['provider' => 'gitlab', 'credential_version' => 6]],
            array_map(static fn(array $row): array => ['provider' => (string) $row['provider'], 'credential_version' => (int) $row['credential_version']], $state->fetchAll(PDO::FETCH_ASSOC)),
        );

        // Invalidation by platform or by repository scope never crosses platforms.
        (new PreviewCaches(new Connection($this->settings, $this->pdo)))->invalidate($this->userId, Provider::GitHub, $gitlabScope);
        self::assertNotNull($newer->get($newer->key('fresh', 1)));
        (new PreviewCaches(new Connection($this->settings, $this->pdo)))->invalidateUser($this->userId, Provider::GitHub);
        self::assertNull($github->get($github->key('same-path', 1)));
        self::assertNotNull($newer->get($newer->key('fresh', 1)));

        // A quota cooldown belongs to the platform that reported it.
        $this->pdo->exec("UPDATE api_cache_state SET blocked_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 60 SECOND) WHERE provider = 'gitlab'");
        try {
            $newer->checkCooldown();
            self::fail('Expected the GitLab cooldown.');
        } catch (Failure $error) {
            self::assertSame(['gitlab_rate_limited', 'GitLab request quota is temporarily unavailable.'], [$error->errorCode, $error->getMessage()]);
        }
        $github->checkCooldown();

        (new PreviewCaches(new Connection($this->settings, $this->pdo)))->invalidateUser($this->userId);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_cache WHERE user_id = ' . $this->userId));
        self::assertSame(0, $this->number('SELECT reserved_entries FROM cache_capacity_state'));
    }

    public function testGitLabManifestIsOneCachedEntryAndTreePagesAreNotStored(): void
    {
        $upstream = new GitLabRepositoryTransport();
        $upstream->extraFiles = 250;
        $client = function () use ($upstream): GitLabClient {
            $transport = new CachedTransport($this->connection, new SourceAccess(Provider::GitLab, $this->userId, 1, $upstream, 'https://gitlab.example', new GitLabCachePolicy()), new PreviewCaches($this->connection), PreviewCache::scope(Provider::GitLab, '42'));
            $transport->bindRepository(42);
            return new GitLabClient($transport, $transport);
        };
        $first = $client()->files(42, GitLabRepositoryTransport::SHA);
        self::assertCount(254, $first['files']);
        self::assertCount(3, $upstream->paths);
        self::assertSame(1, $this->number("SELECT COUNT(*) FROM api_cache WHERE provider = 'gitlab'"));
        self::assertSame($first, $client()->files(42, GitLabRepositoryTransport::SHA));
        self::assertCount(3, $upstream->paths);
        // Folder pages and file content are ordinary immutable entries of the GitLab cache.
        $client()->directory(42, '', GitLabRepositoryTransport::SHA);
        $client()->file(42, 'README.md', GitLabRepositoryTransport::SHA);
        $client()->directory(42, '', GitLabRepositoryTransport::SHA);
        self::assertCount(5, $upstream->paths);
        self::assertSame(3, $this->number("SELECT COUNT(*) FROM api_cache WHERE provider = 'gitlab' AND TIMESTAMPDIFF(SECOND, created_at, expires_at) > 3000"));
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM api_cache WHERE provider = 'github'"));
    }

    public function testUnauthorizedResponseRejectsOnlyTheCurrentCredentialOfItsOwner(): void
    {
        $other = Workspace::user($this->pdo, 'other');
        $connection = $this->pdo->prepare("INSERT INTO github_connections (user_id, encrypted_token, encryption_key_id, credential_version, last_check_status, created_at, updated_at)
            VALUES (?, 'x', 'test-key', ?, 'connected', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
        $connection->execute([$this->userId, 3]);
        $connection->execute([$other, 1]);
        $otherCache = new PreviewCache($this->connection, $this->access($this->upstream, 1, $other), new PreviewCaches($this->connection));
        $otherCache->put($otherCache->key('kept', 1), $this->scope(), null, new HttpResult(200, [], '{}'), 3600);
        $this->browse([]);
        // A late 401 for an older credential version does not mark the newer token rejected.
        $upstream = new FakeTransport([new HttpResult(401, [], '{}')]);
        try {
            (new GitHubClient(new CachedTransport($this->connection, $this->access($upstream, 2), new PreviewCaches($this->connection), $this->scope(), true)))->metadata('fixture', 'alpha');
            self::fail('Expected rejection.');
        } catch (Failure $error) {
            self::assertSame('github_credentials_rejected', $error->errorCode);
        }
        self::assertSame('connected', $this->connectionStatus($this->userId));
        $upstream = new FakeTransport([new HttpResult(401, [], '{}')]);
        try {
            (new GitHubClient(new CachedTransport($this->connection, $this->access($upstream, 3), new PreviewCaches($this->connection), $this->scope(), true)))->metadata('fixture', 'alpha');
            self::fail('Expected rejection.');
        } catch (Failure $error) {
            self::assertSame('github_credentials_rejected', $error->errorCode);
        }
        self::assertSame('credentials_rejected', $this->connectionStatus($this->userId));
        self::assertSame('connected', $this->connectionStatus($other));
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_cache WHERE user_id = ' . $this->userId));
        self::assertNotNull($otherCache->get($otherCache->key('kept', 1)));
    }

    public function testInvalidationDuringUpstreamReadPreventsLateCacheWrite(): void
    {
        $other = (new Connection($this->settings))->get();
        $this->upstream->beforeRequest = function () use ($other): void {
            $path = end($this->upstream->paths);
            if (is_string($path) && str_contains($path, '/contents/')) {
                (new PreviewCaches(new Connection($this->settings, $other)))->invalidate($this->userId, Provider::GitHub, $this->scope());
            }
        };
        $this->browse([]);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_cache'));
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_leases'));
    }

    public function testConditionalRevalidationUsesEtagAndRestoresExpiryWithoutChangingBody(): void
    {
        $body = '{"id":42,"full_name":"fixture/alpha","private":true,"default_branch":"main"}';
        $upstream = new FakeTransport([new HttpResult(200, ['etag' => '"version-1"'], $body), new HttpResult(304, [], '')]);
        $first = (new GitHubClient(new CachedTransport($this->connection, $this->access($upstream), new PreviewCaches($this->connection), $this->scope())))->metadata('fixture', 'alpha');
        $this->pdo->exec('UPDATE api_cache SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        $second = (new GitHubClient(new CachedTransport($this->connection, $this->access($upstream), new PreviewCaches($this->connection), $this->scope())))->metadata('fixture', 'alpha');
        self::assertSame($first, $second);
        self::assertSame([null, '"version-1"'], $upstream->etags);
        self::assertSame(1, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->revalidations);
        self::assertSame(1, $this->number('SELECT COUNT(*) FROM api_cache WHERE expires_at > UTC_TIMESTAMP(6)'));
    }

    public function testTransientRetryIsLimitedToOneWithinTheSharedDeadline(): void
    {
        $upstream = new FakeTransport([new HttpResult(503, [], ''), new HttpResult(200, [], '{}'), new HttpResult(502, [], '')]);
        $transport = new CachedTransport($this->connection, $this->access($upstream), new PreviewCaches($this->connection), $this->scope());
        self::assertSame(200, $transport->get('/repos/fixture/alpha', 15000)->status);
        self::assertSame(502, $transport->get('/repos/fixture/beta', 15000)->status);
        self::assertCount(3, $upstream->paths);
        self::assertNotEmpty($upstream->timeouts);
        foreach ($upstream->timeouts as $timeout) {
            self::assertLessThanOrEqual(15000, $timeout);
        }
        self::assertLessThan($upstream->timeouts[0], $upstream->timeouts[1]);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM api_leases'));
    }

    public function testRateLimitCooldownIsSharedAcrossRequestsAndPreservesSafeDiagnostics(): void
    {
        $upstream = new FakeTransport([new HttpResult(429, ['retry-after' => '30', 'x-ratelimit-limit' => '5000',
            'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) (time() + 30)], 'private details')]);
        (new CachedTransport($this->connection, $this->access($upstream), new PreviewCaches($this->connection), $this->scope()))->get('/repos/fixture/alpha', 15000);
        $unused = new FakeTransport([]);
        try {
            ($this->transportOn(new Connection($this->settings), $this->access($unused), PreviewCache::scope(Provider::GitHub, 'fixture/beta')))->get('/repos/fixture/beta', 15000);
            self::fail('Expected shared cooldown.');
        } catch (Failure $error) {
            self::assertSame('github_rate_limited', $error->errorCode);
            self::assertGreaterThanOrEqual(29, $error->retryAfter);
        }
        self::assertSame([], $unused->paths);
        self::assertSame(0, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->providers['github']->rateRemaining);
        self::assertSame(5000, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->providers['github']->rateLimit);
    }

    public function testDuplicateInFlightRequestReturnsBusyThenReusesCompletedResponse(): void
    {
        $other = new FakeTransport([]);
        $this->upstream->beforeRequest = function () use ($other): void {
            try {
                ($this->transportOn(new Connection($this->settings), $this->access($other), $this->scope()))->get('/repos/fixture/alpha', 15000);
                self::fail('Expected reservation rejection.');
            } catch (Failure $error) {
                self::assertSame('github_busy', $error->errorCode);
            }
        };
        (new CachedTransport($this->connection, $this->access($this->upstream), new PreviewCaches($this->connection), $this->scope()))->get('/repos/fixture/alpha', 15000);
        ($this->transportOn(new Connection($this->settings), $this->access($other), $this->scope()))->get('/repos/fixture/alpha', 15000);
        self::assertSame([], $other->paths);
        self::assertCount(1, $this->upstream->paths);
    }

    public function testEntryAndPayloadBudgetsEvictOldestAndIgnoreOversizedResponses(): void
    {
        $cache = $this->cache();
        for ($i = 0; $i < 270; $i++) {
            $cache->put($cache->key((string) $i, 1), $this->scope(), null, new HttpResult(200, [], '{}'), 3600);
        }
        self::assertSame(256, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->entries);
        self::assertNull($cache->get($cache->key('0', 1)));
        self::assertSame(14, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->evictions);
        $cache->put($cache->key('oversized', 1), $this->scope(), null, new HttpResult(200, [], str_repeat('x', PreviewCache::MAX_ENTRY_BYTES + 1)), 3600);
        self::assertNull($cache->get($cache->key('oversized', 1)));
        for ($i = 0; $i < 90; $i++) {
            $cache->put($cache->key('large-' . $i, 1), $this->scope(), null, new HttpResult(200, [], str_repeat('x', 400000)), 3600);
        }
        self::assertLessThanOrEqual(PreviewCache::MAX_BYTES, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->bytes);
        self::assertLessThanOrEqual(256, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->entries);
    }

    public function testUsersHaveSeparateLimitsAndCountersAndNeverEvictEachOther(): void
    {
        $other = Workspace::user($this->pdo, 'other');
        $mine = $this->cache();
        $theirs = new PreviewCache($this->connection, $this->access($this->upstream, 1, $other), new PreviewCaches($this->connection));
        $theirs->put($theirs->key('kept', 1), $this->scope(), null, new HttpResult(200, [], '{}'), 3600);
        $theirs->count('hits');
        for ($i = 0; $i < PreviewCache::MAX_ENTRIES + 5; $i++) {
            $mine->put($mine->key((string) $i, 1), $this->scope(), null, new HttpResult(200, [], '{}'), 3600);
        }
        self::assertSame([256, 5, 0], [(new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->entries, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->evictions, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->hits]);
        self::assertSame([1, 0, 1], [(new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($other)->entries, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($other)->evictions, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($other)->hits]);
        self::assertNotNull($theirs->get($theirs->key('kept', 1)));
        // Another user's key never reads this user's entry.
        self::assertNull($theirs->get($mine->key('10', 1)));
        self::assertSame($this->number('SELECT COALESCE(SUM(payload_bytes), 0) FROM api_cache'), $this->number('SELECT reserved_bytes FROM cache_capacity_state'));
        self::assertSame($this->number('SELECT COUNT(*) FROM api_cache'), $this->number('SELECT reserved_entries FROM cache_capacity_state'));
    }

    public function testFullGlobalBudgetSkipsCachingInsteadOfEvictingOtherUsers(): void
    {
        $other = Workspace::user($this->pdo, 'other');
        $budget = 1048576;
        $mine = new PreviewCache($this->connection, $this->access($this->upstream), new PreviewCaches($this->connection), $budget);
        for ($i = 0; $i < 3; $i++) {
            $mine->put($mine->key('large-' . $i, 1), $this->scope(), null, new HttpResult(200, [], str_repeat('x', 300000)), 3600);
        }
        $theirs = new PreviewCache($this->connection, $this->access($this->upstream, 1, $other), new PreviewCaches($this->connection), $budget);
        $theirs->put($theirs->key('large', 1), $this->scope(), null, new HttpResult(200, [], str_repeat('y', 300000)), 3600);
        self::assertNull($theirs->get($theirs->key('large', 1)));
        self::assertSame(3, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->entries);
        self::assertLessThanOrEqual($budget, $this->number('SELECT reserved_bytes FROM cache_capacity_state'));
        $theirs->put($theirs->key('small', 1), $this->scope(), null, new HttpResult(200, [], '{}'), 3600);
        self::assertNotNull($theirs->get($theirs->key('small', 1)));
    }

    public function testNewCredentialVersionResetsOnlyThatUserAndOlderRequestsBecomeInert(): void
    {
        $other = Workspace::user($this->pdo, 'other');
        $old = $this->cache();
        $old->put($old->key('entry', 1), $this->scope(), null, new HttpResult(200, [], '{}'), 3600);
        $old->count('misses');
        $theirs = new PreviewCache($this->connection, $this->access($this->upstream, 1, $other), new PreviewCaches($this->connection));
        $theirs->put($theirs->key('entry', 1), $this->scope(), null, new HttpResult(200, [], '{}'), 3600);
        $new = new PreviewCache($this->connection, $this->access($this->upstream, 2), new PreviewCaches($this->connection));
        $new->synchronize();
        self::assertSame([0, 0], [(new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->entries, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->misses]);
        self::assertSame(1, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($other)->entries);
        $stale = $this->cache();
        $stale->put($stale->key('late', 1), $this->scope(), null, new HttpResult(200, [], '{}'), 3600);
        $stale->count('hits');
        self::assertSame([0, 0], [(new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->entries, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->hits]);
        self::assertNull($stale->get($stale->key('late', 1)));
    }

    public function testMaintenanceRemovesExpiredEntriesAndRecalculatesTheBudget(): void
    {
        $cache = $this->cache();
        for ($i = 0; $i < 3; $i++) {
            $cache->put($cache->key((string) $i, 1), $this->scope(), null, new HttpResult(200, [], '{}'), 3600);
        }
        $this->pdo->exec('UPDATE api_cache SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) LIMIT 2');
        $this->pdo->exec('UPDATE cache_capacity_state SET reserved_bytes = 999999, reserved_entries = 99');
        self::assertSame(2, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->purgeExpired());
        self::assertSame(1, $this->number('SELECT COUNT(*) FROM api_cache'));
        self::assertSame(
            [$this->number('SELECT SUM(payload_bytes) FROM api_cache'), 1],
            [$this->number('SELECT reserved_bytes FROM cache_capacity_state'), $this->number('SELECT reserved_entries FROM cache_capacity_state')],
        );
    }

    public function testConcurrentWritersCannotExceedTheSharedPayloadBudget(): void
    {
        $workers = [];
        try {
            for ($i = 0; $i < 6; $i++) {
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__) . '/Fixtures/cache-writer.php', (string) $i, (string) $this->userId],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes[1], $pipes[2]];
            }
            foreach ($workers as [$process, $stdout, $stderr]) {
                self::assertSame("Cache writer completed.\n", stream_get_contents($stdout), (string) stream_get_contents($stderr));
                fclose($stdout);
                fclose($stderr);
                self::assertSame(0, proc_close($process));
            }
        } finally {
            foreach ($workers as [$process, $stdout, $stderr]) {
                if (is_resource($stdout)) {
                    fclose($stdout);
                }
                if (is_resource($stderr)) {
                    fclose($stderr);
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
        self::assertLessThanOrEqual(PreviewCache::MAX_BYTES, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->bytes);
        self::assertGreaterThan(0, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->evictions);
        self::assertLessThanOrEqual(256, (new PreviewCaches(new Connection($this->settings, $this->pdo)))->status($this->userId)->entries);
    }

    /** A transport on its own database connection, like a separate request; its cache writes use the same connection. */
    private function transportOn(Connection $connection, SourceAccess $access, string $scope, bool $refresh = false): CachedTransport
    {
        return new CachedTransport($connection, $access, new PreviewCaches($connection), $scope, $refresh);
    }
}
