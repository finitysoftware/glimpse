<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Accounts\Passwords;
use Glimpse\Accounts\UserProvisioningService;
use Glimpse\Application;
use Glimpse\Auth\Csrf;
use Glimpse\Auth\UserAuth;
use Glimpse\Database\Connection;
use Glimpse\Database\Migrator;
use Glimpse\Failure;
use Glimpse\Http\Cookies;
use Glimpse\Services;
use Glimpse\Settings;
use Glimpse\Sharing\VisitorAccess;
use Glimpse\Source\Lease;
use Glimpse\Source\Provider;
use Glimpse\Tests\Fixtures\Workspace;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class FoundationTest extends TestCase
{
    private PDO $pdo;
    private Settings $settings;
    private UserProvisioningService $users;
    private string $password = 'local test password only';

    protected function setUp(): void
    {
        $dsn = getenv('GLIMPSE_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('GLIMPSE_TEST_DB_DSN is required for a dedicated MySQL/MariaDB test database.');
        }
        if (getenv('GLIMPSE_TEST_DB_RESET') !== '1' || !preg_match('/dbname=glimpse_test(?:;|$)/', $dsn)) {
            self::fail('Destructive tests require dbname=glimpse_test and GLIMPSE_TEST_DB_RESET=1.');
        }
        $this->settings = new Settings(environment: 'test', origin: 'http://127.0.0.1:5173', database: [
            'dsn' => $dsn, 'username' => getenv('GLIMPSE_TEST_DB_USER') ?: 'root', 'password' => getenv('GLIMPSE_TEST_DB_PASSWORD') ?: '',
        ]);
        $this->pdo = (new Connection($this->settings))->get();
        Workspace::migrate($this->settings);
        Workspace::reset($this->pdo);
        $this->users = (new Services($this->settings))->accounts;
        $this->users->create('owner', $this->password);
    }

    public function testMigrationRerunAndNewApplicationPreserveData(): void
    {
        $this->pdo->exec("INSERT INTO audit_events (action, outcome, actor_kind, created_at) VALUES ('persistence_test', 'success', 'system', UTC_TIMESTAMP(6))");
        self::assertSame([], (new Migrator(new Connection($this->settings, $this->pdo), dirname(__DIR__, 2) . '/migrations'))->run()->applied);
        $newConnection = (new Connection($this->settings))->get();
        $read = $newConnection->query("SELECT COUNT(*) FROM audit_events WHERE action = 'persistence_test'");
        self::assertNotFalse($read);
        self::assertSame(1, (int) $read->fetchColumn());
        self::assertSame('+00:00', $this->scalar('SELECT @@session.time_zone'));
    }

    public function testLoginCsrfLogoutAndExpiredSession(): void
    {
        $login = $this->send('POST', '/api/auth/login', ['username' => ' Owner ', 'password' => $this->password]);
        self::assertSame(200, $login->getStatusCode());
        $data = $this->data($login);
        $token = $this->cookie($login);
        self::assertSame(['id' => (int) $this->scalar("SELECT id FROM users WHERE username = 'owner'"), 'username' => 'owner'], $data['user']);
        self::assertSame(Csrf::token(UserAuth::AUDIENCE, $token), $data['csrfToken']);
        self::assertSame(['configured' => false, 'status' => 'not_configured', 'credentialVersion' => 0], $data['github']);
        self::assertStringContainsString('Path=/api; Max-Age=28800; HttpOnly; SameSite=Lax', $login->getHeaderLine('Set-Cookie'));
        self::assertStringNotContainsString('Domain=', $login->getHeaderLine('Set-Cookie'));
        self::assertSame(32, strlen((string) $this->scalar('SELECT token_digest FROM sessions')));
        self::assertSame(['user', 'workspace', 1], [$this->scalar('SELECT kind FROM sessions'), $this->scalar('SELECT audience FROM sessions'), (int) $this->scalar('SELECT session_version FROM sessions')]);
        self::assertNotNull($this->scalar("SELECT last_login_at FROM users WHERE username = 'owner'"));
        self::assertSame($data, $this->data($this->send('GET', '/api/auth/session', token: $token)));
        self::assertSame(200, $this->send('GET', '/api/workspace/preview-cache', token: $token)->getStatusCode());
        self::assertArrayNotHasKey('phpVersion', $this->data($this->send('GET', '/api/workspace/preview-cache', token: $token)));
        self::assertSame(404, $this->send('GET', '/api/workspace/diagnostics', token: $token)->getStatusCode());
        self::assertSame(403, $this->send('POST', '/api/workspace/connections/github/probe', [], $token)->getStatusCode());
        self::assertSame(403, $this->send('POST', '/api/workspace/connections/github/probe', [], $token, Csrf::token(VisitorAccess::AUDIENCE, $token))->getStatusCode());
        self::assertSame(409, $this->send('POST', '/api/workspace/connections/github/probe', [], $token, $data['csrfToken'])->getStatusCode());
        self::assertSame(403, $this->send('POST', '/api/auth/logout', [], $token)->getStatusCode());
        $logout = $this->send('POST', '/api/auth/logout', [], $token, $data['csrfToken']);
        self::assertSame(200, $logout->getStatusCode());
        self::assertStringContainsString(Cookies::WORKSPACE . '=; Path=/api; Max-Age=0', $logout->getHeaderLine('Set-Cookie'));
        self::assertSame(401, $this->send('GET', '/api/workspace/preview-cache', token: $token)->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM sessions'));
        self::assertSame(['login_succeeded', 'logout'], $this->column("SELECT action FROM audit_events WHERE actor_kind = 'user' AND action IN ('login_succeeded', 'logout') ORDER BY id"));

        $expired = $this->login();
        $this->pdo->exec('UPDATE sessions SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        self::assertSame(401, $this->send('GET', '/api/auth/session', token: $expired)->getStatusCode());
        $revoked = $this->login();
        $this->pdo->exec('UPDATE sessions SET revoked_at = UTC_TIMESTAMP(6)');
        self::assertSame(401, $this->send('GET', '/api/auth/session', token: $revoked)->getStatusCode());
    }

    public function testSessionVersionStatusAndRoleAreCheckedOnEveryRequest(): void
    {
        $token = $this->login();
        $this->users->resetPassword('owner', 'replacement password value');
        self::assertSame(401, $this->send('GET', '/api/auth/session', token: $token)->getStatusCode());
        self::assertSame(401, $this->send('POST', '/api/auth/login', ['username' => 'owner', 'password' => $this->password])->getStatusCode());
        $this->password = 'replacement password value';
        $token = $this->login();
        $this->users->disable('owner');
        self::assertSame(401, $this->send('GET', '/api/workspace/repositories', token: $token)->getStatusCode());
        $disabled = $this->send('POST', '/api/auth/login', ['username' => 'owner', 'password' => $this->password]);
        self::assertSame([401, 'invalid_credentials', 'Invalid username or password.'], [$disabled->getStatusCode(), $this->error($disabled)['code'], $this->error($disabled)['message']]);
        $this->users->enable('owner');
        $token = $this->login();
        // A platform administrator role does not replace the workspace membership.
        $this->users->grantPlatformAdmin('owner');
        $this->pdo->exec("DELETE FROM user_roles WHERE role = 'member'");
        self::assertSame(401, $this->send('GET', '/api/auth/session', token: $token)->getStatusCode());
        self::assertSame(401, $this->send('POST', '/api/auth/login', ['username' => 'owner', 'password' => $this->password])->getStatusCode());
    }

    public function testFailedSignInsAreGenericAndAuditedWithoutCredentials(): void
    {
        $responses = [];
        foreach ([['nobody', $this->password], ['owner', 'wrong password value'], ['x', $this->password], ['owner ', 'wrong']] as [$username, $password]) {
            $response = $this->send('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
            $responses[] = [$response->getStatusCode(), $this->error($response)['code'], $this->error($response)['message']];
        }
        self::assertSame(array_fill(0, 4, [401, 'invalid_credentials', 'Invalid username or password.']), $responses);
        self::assertSame(400, $this->send('POST', '/api/auth/login', ['username' => 'owner'])->getStatusCode());
        $owner = (int) $this->scalar("SELECT id FROM users WHERE username = 'owner'");
        self::assertSame([null, (string) $owner, null, (string) $owner], array_map(
            static fn(mixed $value): ?string => $value === null ? null : (string) $value,
            $this->column("SELECT subject_user_id FROM audit_events WHERE action = 'login_failed' ORDER BY id"),
        ));
        $audit = (string) json_encode($this->column("SELECT CONCAT_WS('|', action, COALESCE(metadata, ''), COALESCE(error_code, '')) FROM audit_events"));
        foreach ([$this->password, 'wrong password value', 'nobody'] as $secret) {
            self::assertStringNotContainsString($secret, $audit);
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM sessions'));
    }

    public function testLoginAttemptsAreLimitedPerUsernameAndAddress(): void
    {
        for ($attempt = 0; $attempt < UserAuth::USERNAME_LIMIT; $attempt++) {
            self::assertSame(401, $this->send('POST', '/api/auth/login', ['username' => 'owner', 'password' => 'wrong password'])->getStatusCode());
        }
        $response = $this->send('POST', '/api/auth/login', ['username' => 'OWNER', 'password' => $this->password]);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('900', $response->getHeaderLine('Retry-After'));
        self::assertSame(429, $this->send('POST', '/api/auth/login', ['username' => 'owner', 'password' => $this->password], address: '192.0.2.10')->getStatusCode());
        // Unknown names count too; the address limit then stops further guesses from the same client.
        for ($attempt = UserAuth::USERNAME_LIMIT + 1; $attempt < UserAuth::ADDRESS_LIMIT; $attempt++) {
            self::assertSame(401, $this->send('POST', '/api/auth/login', ['username' => 'guess' . $attempt, 'password' => 'wrong password'])->getStatusCode());
        }
        self::assertSame(429, $this->send('POST', '/api/auth/login', ['username' => 'another', 'password' => 'wrong password'])->getStatusCode());
        self::assertSame(['username', 'address'], $this->column("SELECT JSON_VALUE(metadata, '$.scope') FROM audit_events WHERE action = 'login_throttled' ORDER BY id"));
    }

    public function testSuccessfulLoginRotatesSessionAndUpgradesAnOlderHash(): void
    {
        $older = password_hash('short', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->pdo->prepare("INSERT INTO users (username, password_hash, password_changed_at, created_at, updated_at) VALUES ('older', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")->execute([$older]);
        $this->pdo->exec("INSERT INTO user_roles (user_id, role, created_at) SELECT id, 'member', UTC_TIMESTAMP(6) FROM users WHERE username = 'older'");
        $first = $this->cookie($this->send('POST', '/api/auth/login', ['username' => 'older', 'password' => 'short']));
        $hash = (string) $this->scalar("SELECT password_hash FROM users WHERE username = 'older'");
        self::assertNotSame($older, $hash);
        self::assertFalse(Passwords::rehash('short', $hash) !== null);
        self::assertTrue(password_verify('short', $hash));
        self::assertSame(1, (int) $this->scalar("SELECT session_version FROM users WHERE username = 'older'"));
        $second = $this->cookie($this->send('POST', '/api/auth/login', ['username' => 'older', 'password' => 'short'], token: $first));
        self::assertNotSame($first, $second);
        self::assertSame(401, $this->send('GET', '/api/auth/session', token: $first)->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/auth/session', token: $second)->getStatusCode());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM sessions'));
    }

    public function testDummyHashesUseCurrentHashParameters(): void
    {
        self::assertSame(password_get_info(password_hash('x', PASSWORD_BCRYPT))['options'], password_get_info(Passwords::DUMMY_BCRYPT)['options']);
        if (Passwords::argon2id()) {
            self::assertSame(password_get_info(password_hash('x', PASSWORD_ARGON2ID))['options'], password_get_info(Passwords::DUMMY_ARGON2ID)['options']);
        }
        self::assertFalse(Passwords::verify('glimpse-dummy-password-never-valid', null));
    }

    public function testAudiencesDoNotAcceptEachOthersSessions(): void
    {
        $visitor = str_repeat('b', 64);
        $this->pdo->prepare("INSERT INTO sessions (token_digest, kind, audience, created_at, expires_at) VALUES (?, 'visitor', 'visitor', UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR))")
            ->execute([hash('sha256', $visitor, true)]);
        self::assertSame(401, $this->send('GET', '/api/workspace/preview-cache', token: $visitor)->getStatusCode());
        $workspace = $this->login();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/shares/' . str_repeat('a', 32) . '/session')
            ->withCookieParams([Cookies::VISITOR => $workspace]);
        self::assertSame(404, Application::create($this->settings)->handle($request)->getStatusCode());
    }

    public function testLeaseRejectsDuplicateAndReleasesOnFailure(): void
    {
        $lease = new Lease($this->pdo, $this->owner(), 1);
        try {
            $lease->run('same-probe', function () use ($lease): void {
                self::assertFalse($this->pdo->inTransaction());
                $lease->run('same-probe', static fn(): string => 'unexpected');
            });
            self::fail('Expected duplicate rejection.');
        } catch (Failure $error) {
            self::assertSame('github_busy', $error->errorCode);
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM api_leases'));
        self::assertSame('available', $lease->run('same-probe', static fn(): string => 'available'));
        // The same key of another user or credential version is a different request.
        $other = $this->users->create('other', $this->password);
        self::assertSame('both', $lease->run('same-probe', fn(): string => (new Lease($this->pdo, $other, 1))->run('same-probe', static fn(): string => 'both')));
        self::assertSame('both', $lease->run('same-probe', fn(): string => (new Lease($this->pdo, $this->owner(), 2))->run('same-probe', static fn(): string => 'both')));
    }

    public function testLeaseSlotsOfOneUserAreSharedByBothPlatforms(): void
    {
        $owner = $this->owner();
        $busy = static function (callable $attempt): string {
            try {
                $attempt();
                return 'available';
            } catch (Failure $error) {
                return $error->errorCode;
            }
        };
        $github = new Lease($this->pdo, $owner, 1, Provider::GitHub);
        $gitlab = new Lease($this->pdo, $owner, 1, Provider::GitLab);
        // The same key on the other platform is a different request, but the two together use both of the user's slots.
        $result = $github->run('same', fn(): array => $gitlab->run('same', fn(): array => [
            $busy(fn(): string => (new Lease($this->pdo, $owner, 1, Provider::GitHub))->run('third', static fn(): string => 'unexpected')),
            $busy(fn(): string => (new Lease($this->pdo, $owner, 1, Provider::GitLab))->run('third', static fn(): string => 'unexpected')),
        ]));
        self::assertSame(['github_busy', 'gitlab_busy'], $result);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM api_leases'));
        self::assertSame(['github', 'gitlab'], $this->column('SELECT provider FROM api_cache_state WHERE user_id = ' . $owner . ' ORDER BY provider'));
    }

    public function testLeaseLimitsEachUserAndTheHostAndExpires(): void
    {
        $owner = $this->owner();
        $other = $this->users->create('other', $this->password);
        $third = $this->users->create('third', $this->password);
        $held = [];
        $nested = function (int $userId, string $key, callable $inside) use (&$held): mixed {
            return (new Lease($this->pdo, $userId, 1))->run($key, function () use ($userId, $inside, &$held): mixed {
                $held[] = $userId;
                return $inside();
            });
        };
        $busy = static function (callable $attempt): string {
            try {
                $attempt();
                return 'available';
            } catch (Failure $error) {
                return $error->errorCode;
            }
        };
        $result = $nested($owner, 'a', fn(): mixed => $nested($owner, 'b', fn(): array => [
            // A third concurrent request of the same user is rejected although host slots are free.
            $busy(fn(): string => (new Lease($this->pdo, $owner, 1))->run('c', static fn(): string => 'unexpected')),
            // Other users keep their share of the host slots.
            $nested($other, 'a', fn(): mixed => $nested($third, 'a', fn(): array => [
                (int) $this->scalar('SELECT COUNT(*) FROM api_leases'),
                $busy(fn(): string => (new Lease($this->pdo, $other, 1))->run('b', static fn(): string => 'unexpected')),
            ])),
        ]));
        self::assertSame(['github_busy', [4, 'github_busy']], $result);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM api_leases'));
        $slot = $this->pdo->prepare('INSERT INTO api_leases (slot, user_id, credential_version, request_key, owner_token, expires_at) VALUES (?, ?, 1, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 20 SECOND))');
        $slot->execute([1, $other, random_bytes(32), random_bytes(32)]);
        $slot->execute([2, $other, random_bytes(32), random_bytes(32)]);
        $slot->execute([3, $third, random_bytes(32), random_bytes(32)]);
        $slot->execute([4, $third, random_bytes(32), random_bytes(32)]);
        self::assertSame('github_busy', $busy(fn(): string => (new Lease($this->pdo, $owner, 1))->run('new', static fn(): string => 'unexpected')));
        $this->pdo->exec('UPDATE api_leases SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        self::assertSame('available', (new Lease($this->pdo, $owner, 1))->run('new', static fn(): string => 'available'));
    }

    public function testInterruptedMigrationRequiresManualRecovery(): void
    {
        $this->pdo->exec('UPDATE schema_migrations SET completed = FALSE');
        try {
            (new Migrator(new Connection($this->settings, $this->pdo), dirname(__DIR__, 2) . '/migrations'))->run();
            self::fail('Expected interrupted migration rejection.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('manual recovery', $error->getMessage());
        } finally {
            $this->pdo->exec('UPDATE schema_migrations SET completed = TRUE');
        }
    }

    private function owner(): int
    {
        return (int) $this->scalar("SELECT id FROM users WHERE username = 'owner'");
    }

    private function login(): string
    {
        $response = $this->send('POST', '/api/auth/login', ['username' => 'owner', 'password' => $this->password]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->cookie($response);
    }

    private function cookie(ResponseInterface $response): string
    {
        self::assertSame(1, preg_match('/' . Cookies::WORKSPACE . '=([a-f0-9]{64})/', $response->getHeaderLine('Set-Cookie'), $matches));
        return $matches[1] ?? '';
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        return $statement->fetchColumn();
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @return array{code: string, message: string} */
    private function error(ResponseInterface $response): array
    {
        $error = $this->data($response)['error'] ?? null;
        self::assertIsArray($error);
        return ['code' => (string) ($error['code'] ?? ''), 'message' => (string) ($error['message'] ?? '')];
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, ?string $token = null, ?string $csrf = null, string $address = '127.0.0.1'): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => $address]);
        if ($method === 'POST') {
            $request = $request->withHeader('Origin', $this->settings->origin)->withHeader('Content-Type', 'application/json');
            $request->getBody()->write(json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR));
            $request->getBody()->rewind();
        }
        if ($token !== null) {
            $request = $request->withCookieParams([Cookies::WORKSPACE => $token]);
        }
        if ($csrf !== null) {
            $request = $request->withHeader('X-CSRF-Token', $csrf);
        }
        return Application::create($this->settings)->handle($request);
    }
}
