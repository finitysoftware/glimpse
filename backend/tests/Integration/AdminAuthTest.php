<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Accounts\UserProvisioningService;
use Glimpse\Application;
use Glimpse\Auth\AdminAuth;
use Glimpse\Auth\Csrf;
use Glimpse\Auth\UserAuth;
use Glimpse\Http\Cookies;
use Glimpse\Services;
use Glimpse\Settings;
use Glimpse\Tests\Fixtures\Workspace;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Admin panel sign-in: separate audience, cookie and CSRF scope from the workspace. */
final class AdminAuthTest extends TestCase
{
    private const PASSWORD = 'admin panel test password';
    private Settings $settings;
    private PDO $pdo;
    private UserProvisioningService $users;

    protected function setUp(): void
    {
        $dsn = getenv('GLIMPSE_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('A dedicated MySQL/MariaDB test database is required.');
        }
        if (getenv('GLIMPSE_TEST_DB_RESET') !== '1' || !preg_match('/dbname=glimpse_test(?:;|$)/', $dsn)) {
            self::fail('Refusing to reset a non-test database.');
        }
        $this->settings = new Settings(
            environment: 'test',
            origin: 'https://glimpse.example',
            database: ['dsn' => $dsn,
                'username' => getenv('GLIMPSE_TEST_DB_USER') ?: 'root', 'password' => getenv('GLIMPSE_TEST_DB_PASSWORD') ?: ''],
            githubTokenKey: Workspace::tokenKey(),
            githubTokenKeyId: Workspace::TOKEN_KEY_ID,
        );
        $this->pdo = Workspace::migrate($this->settings);
        Workspace::reset($this->pdo);
        $this->users = (new Services($this->settings))->accounts;
        $this->users->create('root.admin', self::PASSWORD, true);
        $this->users->create('member', self::PASSWORD);
    }

    public function testOnlyActiveAdministratorsSignInAndEveryFailureLooksTheSame(): void
    {
        $response = $this->login('Root.Admin');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertMatchesRegularExpression('/^glimpse_platform_admin=[a-f0-9]{64}; Path=\/api\/admin; Max-Age=28800; HttpOnly; SameSite=Strict; Secure$/', $cookie);
        $data = $this->data($response);
        self::assertSame(['id', 'username', 'firstName', 'lastName', 'email'], array_keys($data['admin']));
        self::assertSame(['root.admin', false], [$data['admin']['username'], $data['passwordChangeRequired']]);
        self::assertStringNotContainsString('password_hash', (string) $response->getBody());
        self::assertStringNotContainsString(self::PASSWORD, (string) $response->getBody());

        $this->users->disable('root.admin');
        $this->users->create('disabled.admin', self::PASSWORD, true);
        $this->users->disable('disabled.admin');
        foreach ([['member', self::PASSWORD], ['root.admin', self::PASSWORD], ['disabled.admin', self::PASSWORD], ['nobody', self::PASSWORD], ['member', 'wrong password']] as [$username, $password]) {
            $failed = $this->login($username, $password);
            self::assertSame([401, 'invalid_credentials', 'Invalid username or password.'], [$failed->getStatusCode(), $this->data($failed)['error']['code'], $this->data($failed)['error']['message']]);
            self::assertSame('', $failed->getHeaderLine('Set-Cookie'));
        }
        // An account needs only the admin role; the member role is not required.
        $this->users->enable('root.admin');
        $this->pdo->exec("DELETE FROM user_roles WHERE role = 'member' AND user_id = (SELECT id FROM users WHERE username = 'root.admin')");
        self::assertSame(200, $this->login('root.admin')->getStatusCode());
    }

    public function testAdminAndWorkspaceSessionsNeverOpenEachOther(): void
    {
        $this->users->grantPlatformAdmin('member');
        $admin = $this->token($this->login('member'));
        $workspace = (new Services($this->settings))->auth->login('member', self::PASSWORD, 'fixture')->token;
        self::assertSame(200, $this->send('GET', '/api/admin/auth/session', admin: $admin)->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/auth/session', workspace: $workspace)->getStatusCode());
        // The token of one audience is rejected by the other even under the other cookie name.
        self::assertSame(401, $this->send('GET', '/api/admin/auth/session', admin: $workspace)->getStatusCode());
        self::assertSame(401, $this->send('GET', '/api/auth/session', workspace: $admin)->getStatusCode());
        self::assertSame(401, $this->send('GET', '/api/admin/auth/session', workspace: $workspace)->getStatusCode());
        // CSRF is bound to the audience: the workspace token never validates an admin change.
        $wrong = $this->send('POST', '/api/admin/auth/logout', [], admin: $admin, csrf: Csrf::token(UserAuth::AUDIENCE, $admin));
        self::assertSame([403, 'csrf_failed'], [$wrong->getStatusCode(), $this->data($wrong)['error']['code']]);
        self::assertSame(403, $this->send('POST', '/api/admin/auth/logout', [], admin: $admin, origin: 'https://attacker.example')->getStatusCode());
        $logout = $this->send('POST', '/api/admin/auth/logout', [], admin: $admin);
        self::assertSame(200, $logout->getStatusCode());
        self::assertStringStartsWith('glimpse_platform_admin=; Path=/api/admin; Max-Age=0', $logout->getHeaderLine('Set-Cookie'));
        self::assertSame(401, $this->send('GET', '/api/admin/auth/session', admin: $admin)->getStatusCode());
        // Signing out of the admin panel leaves the workspace session untouched.
        self::assertSame(200, $this->send('GET', '/api/auth/session', workspace: $workspace)->getStatusCode());
        // A workspace sign-in ends only this browser's workspace session, never an admin session sent under the wrong cookie.
        $again = $this->token($this->login('member'));
        self::assertSame(200, $this->send('POST', '/api/auth/login', ['username' => 'member', 'password' => self::PASSWORD], workspace: $again)->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/admin/auth/session', admin: $again)->getStatusCode());
    }

    public function testRoleRevocationAndDisablingEndAdminSessionsImmediately(): void
    {
        $first = $this->token($this->login('root.admin'));
        $this->users->grantPlatformAdmin('member');
        $second = $this->token($this->login('member'));
        $this->users->revokePlatformAdmin('member');
        self::assertSame(401, $this->send('GET', '/api/admin/auth/session', admin: $second)->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/admin/auth/session', admin: $first)->getStatusCode());
        $this->pdo->exec("UPDATE users SET must_change_password = TRUE WHERE username = 'root.admin'");
        self::assertTrue($this->data($this->send('GET', '/api/admin/auth/session', admin: $first))['passwordChangeRequired']);
        $this->users->disable('root.admin');
        self::assertSame(401, $this->send('GET', '/api/admin/auth/session', admin: $first)->getStatusCode());
    }

    public function testSignInIsThrottledCountedAndNeverAudited(): void
    {
        foreach (range(1, AdminAuth::USERNAME_LIMIT) as $ignored) {
            self::assertSame(401, $this->login('root.admin', 'wrong password')->getStatusCode());
        }
        $limited = $this->login('root.admin');
        self::assertSame([429, 'rate_limited'], [$limited->getStatusCode(), $this->data($limited)['error']['code']]);
        self::assertNotSame('', $limited->getHeaderLine('Retry-After'));
        $this->pdo->exec('DELETE FROM auth_throttles');
        $audit = (int) $this->scalar('SELECT COUNT(*) FROM audit_events');
        self::assertSame(200, $this->login('root.admin')->getStatusCode());
        self::assertSame(200, $this->send('POST', '/api/admin/auth/logout', [], admin: $this->token($this->login('root.admin')))->getStatusCode());
        self::assertSame(401, $this->login('root.admin', 'wrong password')->getStatusCode());
        self::assertSame($audit, (int) $this->scalar('SELECT COUNT(*) FROM audit_events'));
        self::assertSame(2, (int) $this->scalar("SELECT count FROM daily_metrics WHERE metric = 'admin_login' AND day = UTC_DATE()"));
        self::assertNotNull($this->scalar("SELECT last_login_at FROM users WHERE username = 'root.admin'"));
    }

    public function testShareAliasHostDoesNotServeTheAdminApi(): void
    {
        $this->settings = new Settings(
            environment: 'test',
            origin: 'https://glimpse.example',
            database: $this->settings->database,
            githubTokenKey: Workspace::tokenKey(),
            githubTokenKeyId: Workspace::TOKEN_KEY_ID,
            shareAliasOrigins: ['https://old.example'],
        );
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/auth/session', ['HTTP_HOST' => 'old.example']);
        $response = Application::create($this->settings)->handle($request);
        self::assertSame([403, 'host_rejected'], [$response->getStatusCode(), $this->data($response)['error']['code']]);
        self::assertSame(401, $this->send('GET', '/api/admin/auth/session')->getStatusCode());
        self::assertSame(404, $this->send('GET', '/api/admin/diagnostics', admin: $this->token($this->login('root.admin')))->getStatusCode());
    }

    private function login(string $username, string $password = self::PASSWORD): ResponseInterface
    {
        return $this->send('POST', '/api/admin/auth/login', ['username' => $username, 'password' => $password]);
    }

    private function token(ResponseInterface $response): string
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, preg_match('/^glimpse_platform_admin=([a-f0-9]{64});/', $response->getHeaderLine('Set-Cookie'), $match));
        return $match[1] ?? '';
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, ?string $admin = null, ?string $workspace = null, ?string $csrf = null, ?string $origin = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        $cookies = [];
        if ($admin !== null) {
            $cookies[Cookies::ADMIN] = $admin;
            $csrf ??= Csrf::token(AdminAuth::AUDIENCE, $admin);
        }
        if ($workspace !== null) {
            $cookies[Cookies::WORKSPACE] = $workspace;
            $csrf ??= Csrf::token(UserAuth::AUDIENCE, $workspace);
        }
        $request = $request->withCookieParams($cookies);
        if ($csrf !== null) {
            $request = $request->withHeader('X-CSRF-Token', $csrf);
        }
        if ($body !== null) {
            $request = $request->withHeader('Origin', $origin ?? $this->settings->origin)->withHeader('Content-Type', 'application/json');
            $request->getBody()->write(json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR));
            $request->getBody()->rewind();
        }
        return Application::create($this->settings)->handle($request);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    private function scalar(string $sql): mixed
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        return $read->fetchColumn();
    }
}
