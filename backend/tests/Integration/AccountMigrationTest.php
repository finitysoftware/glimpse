<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Database\Connection;
use Glimpse\Database\MigrationException;
use Glimpse\Database\MigrationResult;
use Glimpse\Database\Migrator;
use Glimpse\Failure;
use Glimpse\Services;
use Glimpse\Settings;
use Glimpse\Tests\Fixtures\ScratchDatabase;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Runs against a recreated scratch database, so every migration starts from an empty database like a new installation. */
final class AccountMigrationTest extends TestCase
{
    private const PASSWORD = 'first account password';

    /** @var array{dsn: string, username: string, password: string} */
    private array $database;
    private string $tokenKey;
    private PDO $pdo;
    private string $cliOutput = '';

    protected function setUp(): void
    {
        $this->database = ScratchDatabase::reset();
        $this->tokenKey = base64_encode(random_bytes(32));
        $this->pdo = (new Connection($this->settings()))->get();
    }

    public function testTargetsAndPreconditionsProtectTheOwnershipSchema(): void
    {
        self::assertSame(['001_foundation.sql', '002_share_url_storage.sql', '003_preview_cache.sql'], $this->migrate('003')->applied);
        self::assertSame(['004_share_permissions.sql', '005_user_accounts.sql'], $this->migrate('005_user_accounts')->applied);
        foreach (['999', '00', '005; DROP', '../005'] as $target) {
            try {
                $this->migrate($target);
                self::fail('Unknown target was accepted.');
            } catch (MigrationException $error) {
                self::assertStringContainsString('Unknown migration target', $error->getMessage());
            }
        }
        // Rows without an owner come from a single-owner release; 006 never runs over them and nothing is recorded.
        $this->pdo->exec("INSERT INTO repositories (github_id, owner, name, default_branch, created_at, updated_at) VALUES (9, 'single', 'owner', 'main', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
        $this->pdo->exec("INSERT INTO audit_events (action, outcome, created_at) VALUES ('share_created', 'success', UTC_TIMESTAMP(6))");
        $blocked = $this->migrate('007');
        self::assertSame([[], '006_enforce_ownership.sql'], [$blocked->applied, $blocked->blockedBy]);
        self::assertStringContainsString('1 repositories, 0 links and 1 audit events', (string) $blocked->reason);
        $states = array_column((new Migrator(new Connection($this->settings(), $this->pdo), dirname(__DIR__, 2) . '/migrations'))->status(), 'state', 'version');
        self::assertSame(['applied', 'pending', 'pending'], [$states['005_user_accounts.sql'], $states['006_enforce_ownership.sql'], $states['007_file_visibility.sql']]);
        $this->pdo->exec('DELETE FROM repositories');
        $this->pdo->exec('DELETE FROM audit_events');
        self::assertSame(['006_enforce_ownership.sql'], $this->migrate('006')->applied);

        $this->pdo->exec("UPDATE schema_migrations SET checksum = REPEAT('x', 32) WHERE version = '005_user_accounts.sql'");
        try {
            $this->migrate();
            self::fail('Changed migration was accepted.');
        } catch (MigrationException $error) {
            self::assertStringContainsString('manual recovery required: 005_user_accounts.sql', $error->getMessage());
        }
    }

    public function testNewInstallationMigratesInOneRunAndEnforcesTenantBoundaries(): void
    {
        $result = $this->migrate();
        self::assertFalse($result->blocked());
        self::assertCount(9, $result->applied);
        self::assertSame('009_gitlab.sql', $result->applied[8]);
        self::assertSame([], $this->migrate()->applied);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM users'));
        $users = (new Services($this->settings()))->accounts;
        $a = $users->create('alice', 'alice account password');
        $b = $users->create('bob', 'bob account password');

        $repository = $this->pdo->prepare('INSERT INTO repositories (user_id, external_id, owner, name, default_branch, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
        $repository->execute([$a, 100, 'acme', 'shared', 'main']);
        $repoA = (int) $this->pdo->lastInsertId();
        // The same GitHub repository may be registered by two users, but only once per user.
        $repository->execute([$b, 100, 'acme', 'shared', 'main']);
        $repoB = (int) $this->pdo->lastInsertId();
        $this->rejects(fn() => $repository->execute([$a, 100, 'acme', 'renamed', 'main']));
        $this->rejects(fn() => $repository->execute([$a, 101, 'acme', 'shared', 'main']));
        $this->rejects(fn() => $this->pdo->exec("INSERT INTO repositories (external_id, owner, name, default_branch, created_at, updated_at) VALUES (5, 'no', 'owner', 'main', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"));

        $link = $this->pdo->prepare('INSERT INTO share_links (user_id, public_id, repository_id, name, secret_digest, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))');
        $link->execute([$a, str_repeat('a', 32), $repoA, 'Alice', random_bytes(32)]);
        $linkA = (int) $this->pdo->lastInsertId();
        $this->rejects(fn() => $link->execute([$b, str_repeat('b', 32), $repoA, 'Cross-tenant', random_bytes(32)]));
        self::assertSame('branch_policies', $this->row('SELECT visibility_mode FROM share_links WHERE id = ' . $linkA)['visibility_mode']);

        $profile = $this->pdo->prepare("INSERT INTO repository_visibility_profiles (user_id, repository_id, branch, mode, selected_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
        $profile->execute([$a, $repoA, 'main', 'selected', 1]);
        $profileA = (int) $this->pdo->lastInsertId();
        $profile->execute([$a, $repoA, 'Main', 'selected', 0]);
        $this->rejects(fn() => $profile->execute([$a, $repoA, 'main', 'all', 0]));
        $this->rejects(fn() => $profile->execute([$b, $repoA, 'develop', 'all', 0]));
        $this->rejects(fn() => $profile->execute([$a, $repoA, 'develop', 'all', 3]));
        $profile->execute([$b, $repoB, 'main', 'all', 0]);
        $file = $this->pdo->prepare('INSERT INTO repository_visibility_files (profile_id, user_id, path, path_hash) VALUES (?, ?, ?, ?)');
        $file->execute([$profileA, $a, 'src/PublicApi.cs', hash('sha256', 'src/PublicApi.cs', true)]);
        $this->rejects(fn() => $file->execute([$profileA, $b, 'README.md', hash('sha256', 'README.md', true)]));

        $policy = $this->pdo->prepare("INSERT INTO share_branch_policies (user_id, share_link_id, branch, mode, source_profile_id, source_profile_version, selected_count, created_at, updated_at) VALUES (?, ?, ?, 'selected', ?, 1, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
        $policy->execute([$a, $linkA, 'main', $profileA]);
        $policyA = (int) $this->pdo->lastInsertId();
        $this->rejects(fn() => $policy->execute([$a, $linkA, 'main', $profileA]));
        $this->rejects(fn() => $policy->execute([$b, $linkA, 'develop', null]));
        $this->pdo->prepare('INSERT INTO share_visibility_files (policy_id, user_id, path, path_hash) VALUES (?, ?, ?, ?)')
            ->execute([$policyA, $a, 'src/PublicApi.cs', hash('sha256', 'src/PublicApi.cs', true)]);
        // Removing the template keeps the link snapshot; deleting the link removes only its own policy rows.
        $this->pdo->exec('DELETE FROM repository_visibility_profiles WHERE id = ' . $profileA);
        self::assertNull($this->row('SELECT source_profile_id FROM share_branch_policies')['source_profile_id']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM share_visibility_files'));
        $this->pdo->exec('DELETE FROM share_links WHERE id = ' . $linkA);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM share_branch_policies') + $this->scalar('SELECT COUNT(*) FROM share_visibility_files'));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM repository_visibility_profiles'));

        $cache = $this->pdo->prepare('INSERT INTO api_cache (cache_key, user_id, credential_version, repository_id, scope_key, payload, payload_bytes, created_at, expires_at) VALUES (?, ?, 0, ?, ?, ?, 2, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))');
        $cache->execute([random_bytes(32), $a, $repoA, random_bytes(32), '{}']);
        $cache->execute([random_bytes(32), $a, null, random_bytes(32), '{}']);
        $this->rejects(fn() => $cache->execute([random_bytes(32), $b, $repoA, random_bytes(32), '{}']));
        $this->rejects(fn() => $this->pdo->exec("INSERT INTO api_cache (cache_key, scope_key, payload, payload_bytes, created_at, expires_at) VALUES (REPEAT('c', 32), REPEAT('s', 32), '{}', 2, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"));

        $session = $this->pdo->prepare('INSERT INTO sessions (token_digest, kind, user_id, session_version, audience, created_at, expires_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR))');
        $session->execute([random_bytes(32), 'user', $a, 1, 'workspace']);
        $session->execute([random_bytes(32), 'visitor', null, null, 'visitor']);
        foreach ([['admin', null, null, null], ['user', null, 1, 'workspace'], ['user', $a, 1, 'visitor'], ['visitor', $a, null, 'visitor'], ['visitor', null, null, 'workspace']] as [$kind, $user, $version, $audience]) {
            $this->rejects(fn() => $session->execute([random_bytes(32), $kind, $user, $version, $audience]));
        }
        $this->rejects(fn() => $this->pdo->exec("INSERT INTO audit_events (action, outcome, created_at) VALUES ('missing_actor', 'success', UTC_TIMESTAMP(6))"));
        $this->rejects(fn() => $this->pdo->exec("INSERT INTO audit_events (action, outcome, actor_kind, created_at) VALUES ('anonymous_user', 'success', 'user', UTC_TIMESTAMP(6))"));
        $this->rejects(fn() => $this->pdo->exec("INSERT INTO audit_events (action, outcome, actor_kind, actor_user_id, created_at) VALUES ('invented_actor', 'success', 'legacy', " . $a . ', UTC_TIMESTAMP(6))'));
        $this->rejects(fn() => $this->pdo->exec("INSERT INTO audit_events (action, outcome, actor_kind, metadata, created_at) VALUES ('bad_metadata', 'success', 'cli', 'not json', UTC_TIMESTAMP(6))"));
        $this->rejects(fn() => $this->pdo->exec("INSERT INTO users (username, password_hash, password_changed_at, created_at, updated_at) VALUES ('Upper', 'x', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"));
    }

    public function testProvisioningManagesAccountsWithoutLeakingPasswords(): void
    {
        $this->migrate();
        $users = (new Services($this->settings()))->accounts;
        $id = $users->create(' Alice ', 'first alice password', true);
        self::assertSame('alice', $this->row('SELECT username FROM users WHERE id = ' . $id)['username']);
        $hash = (string) $this->row('SELECT password_hash FROM users WHERE id = ' . $id)['password_hash'];
        foreach ([['ALICE', 'another valid password', 'username_taken'], ['al', 'another valid password', 'invalid_username'], ['carol', 'short', 'invalid_password']] as [$name, $password, $code]) {
            try {
                $users->create($name, $password);
                self::fail('Invalid account was created.');
            } catch (Failure $error) {
                self::assertSame($code, $error->errorCode);
            }
        }
        self::assertSame($hash, $this->row('SELECT password_hash FROM users WHERE id = ' . $id)['password_hash']);

        $this->pdo->prepare("INSERT INTO sessions (token_digest, kind, user_id, session_version, audience, created_at, expires_at) VALUES (?, 'user', ?, 1, 'workspace', UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR))")
            ->execute([random_bytes(32), $id]);
        $users->resetPassword('alice', 'second alice password');
        $row = $this->row('SELECT password_hash, session_version FROM users WHERE id = ' . $id);
        self::assertTrue(password_verify('second alice password', (string) $row['password_hash']));
        self::assertSame(2, (int) $row['session_version']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM sessions WHERE revoked_at IS NULL'));
        try {
            $users->resetPassword('nobody', 'second alice password');
            self::fail('Missing account was reset.');
        } catch (Failure $error) {
            self::assertSame('user_not_found', $error->errorCode);
        }

        self::assertTrue($users->disable('alice'));
        self::assertFalse($users->disable('alice'));
        self::assertSame(['disabled', 3], [$this->row('SELECT status FROM users')['status'], (int) $this->row('SELECT session_version FROM users')['session_version']]);
        self::assertTrue($users->enable('alice'));
        self::assertFalse($users->enable('alice'));
        self::assertFalse($users->grantPlatformAdmin('alice'));
        self::assertTrue($users->revokePlatformAdmin('alice'));
        self::assertFalse($users->revokePlatformAdmin('alice'));
        self::assertTrue($users->grantPlatformAdmin('alice'));
        self::assertSame(
            [['username' => 'alice', 'status' => 'active', 'roles' => ['member', 'platform_admin']]],
            array_map(static fn(array $user): array => array_intersect_key($user, ['username' => 1, 'status' => 1, 'roles' => 1]), $users->list()),
        );

        $actions = array_column($this->rows("SELECT action FROM audit_events WHERE actor_kind = 'cli' AND subject_user_id = " . $id . ' ORDER BY id'), 'action');
        self::assertSame(['user_created', 'user_password_reset', 'user_disabled', 'user_enabled', 'user_role_revoked', 'user_role_granted'], $actions);
        $audit = (string) json_encode($this->rows('SELECT * FROM audit_events'));
        foreach (['first alice password', 'second alice password', $hash] as $secret) {
            self::assertStringNotContainsString($secret, $audit);
        }
    }

    public function testCliReadsSecretsOnlyFromStandardInputAndNeverPrintsThem(): void
    {
        [$status, $output] = $this->cli('migrate.php', []);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Schema is up to date.', $output);
        [$status, $output] = $this->cli('migrate.php', ['--status']);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('applied     009_gitlab.sql', $output);
        [$status, $output] = $this->cli('users.php', ['create', 'cli.owner', '--platform-admin'], self::PASSWORD . "\n");
        self::assertSame(0, $status, $output);

        [$status, $output] = $this->cli('users.php', ['create', 'Second.User'], "second user password\r\n");
        self::assertSame(0, $status, $output);
        self::assertTrue(password_verify('second user password', (string) $this->row("SELECT password_hash FROM users WHERE username = 'second.user'")['password_hash']));
        [$status, $output] = $this->cli('users.php', ['create', 'second.user'], "another user password\n");
        self::assertSame(1, $status);
        self::assertStringContainsString('already exists', $output);
        [$status, $output] = $this->cli('users.php', ['reset-password', 'second.user'], "\xEF\xBB\xBFbom prefixed password\n");
        self::assertSame(1, $status);
        self::assertStringContainsString('byte order mark', $output);
        [$status, $output] = $this->cli('users.php', ['create', 'third.user', 'argument password']);
        self::assertSame(64, $status);
        [$status, $output] = $this->cli('users.php', ['list']);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('cli.owner', $output);
        foreach ([self::PASSWORD, 'second user password', 'bom prefixed password', $this->tokenKey] as $secret) {
            self::assertStringNotContainsString($secret, $this->cliOutput);
        }
    }

    private function settings(): Settings
    {
        return new Settings(
            environment: 'test',
            origin: 'http://127.0.0.1:5173',
            database: $this->database,
            shareEncryptionKey: base64_encode(str_repeat('s', 32)),
            githubTokenKey: $this->tokenKey,
            githubTokenKeyId: 'gh-test-1',
        );
    }

    private function migrate(?string $target = null): MigrationResult
    {
        return (new Migrator(new Connection($this->settings(), $this->pdo), dirname(__DIR__, 2) . '/migrations'))->run($target);
    }

    private function rejects(callable $statement): void
    {
        try {
            $statement();
            self::fail('The database accepted a row that violates tenant constraints.');
        } catch (PDOException) {
            self::addToAssertionCount(1);
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        /** @var list<array<string, mixed>> */
        return $read->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $row = $this->rows($sql)[0] ?? null;
        self::assertIsArray($row);
        return $row;
    }

    private function scalar(string $sql): int
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        return (int) $read->fetchColumn();
    }

    /** @param list<string> $arguments
     *  @return array{0: int, 1: string}
     */
    private function cli(string $script, array $arguments, string $stdin = ''): array
    {
        $environment = ['GLIMPSE_ENV' => 'test', 'GLIMPSE_ORIGIN' => 'http://127.0.0.1:5173', 'GLIMPSE_DB_DSN' => $this->database['dsn'],
            'GLIMPSE_DB_USER' => $this->database['username'], 'GLIMPSE_DB_PASSWORD' => $this->database['password'],
            'GLIMPSE_SHARE_ENCRYPTION_KEY' => base64_encode(str_repeat('s', 32)),
            'GLIMPSE_GITHUB_TOKEN_KEY' => $this->tokenKey, 'GLIMPSE_GITHUB_TOKEN_KEY_ID' => 'gh-test-1'] + getenv();
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/' . $script, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->cliOutput .= $output;
        return [proc_close($process), $output];
    }
}
