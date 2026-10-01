<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

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

/** Own password changes and the password change an administrator can require. */
final class PasswordChangeTest extends TestCase
{
    private const NEW_PASSWORD = 'a brand new workspace password';
    private Settings $settings;
    private PDO $pdo;

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
    }

    public function testUserChangesOwnPasswordAndOtherSessionsEnd(): void
    {
        $current = Workspace::signIn($this->settings);
        $other = $this->workspaceLogin('owner', Workspace::PASSWORD);
        $audit = (int) $this->scalar('SELECT COUNT(*) FROM audit_events');
        foreach ([['wrong current password', self::NEW_PASSWORD, 422, 'current_password_invalid'], [Workspace::PASSWORD, 'short', 400, 'invalid_password'],
            [Workspace::PASSWORD, Workspace::PASSWORD, 422, 'password_unchanged'], ['', self::NEW_PASSWORD, 400, 'invalid_input']] as [$old, $new, $status, $code]) {
            $rejected = $this->send('POST', '/api/auth/password', ['currentPassword' => $old, 'newPassword' => $new], workspace: $current);
            self::assertSame([$status, $code], [$rejected->getStatusCode(), $this->data($rejected)['error']['code']]);
        }
        $changed = $this->send('POST', '/api/auth/password', ['currentPassword' => Workspace::PASSWORD, 'newPassword' => self::NEW_PASSWORD], workspace: $current);
        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        self::assertSame(1, preg_match('/^glimpse_workspace=([a-f0-9]{64}); Path=\/api;/', $changed->getHeaderLine('Set-Cookie'), $cookie));
        $renewed = $cookie[1] ?? '';
        self::assertSame(['owner', false], [$this->data($changed)['user']['username'], $this->data($changed)['passwordChangeRequired']]);
        self::assertStringNotContainsString(self::NEW_PASSWORD, (string) $changed->getBody());
        // Every earlier session ends; the browser that changed the password continues with its new session.
        self::assertSame(401, $this->send('GET', '/api/auth/session', workspace: $current)->getStatusCode());
        self::assertSame(401, $this->send('GET', '/api/auth/session', workspace: $other)->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/auth/session', workspace: $renewed)->getStatusCode());
        self::assertSame(401, $this->workspaceLoginResponse('owner', Workspace::PASSWORD)->getStatusCode());
        self::assertSame(200, $this->workspaceLoginResponse('owner', self::NEW_PASSWORD)->getStatusCode());
        // Only the sign-ins above are audited; the password change itself writes nothing.
        self::assertSame(['login_failed', 'login_succeeded'], array_column($this->rows('SELECT DISTINCT action FROM audit_events WHERE id > ' . $this->maxAuditBefore($audit) . ' ORDER BY action'), 'action'));
    }

    public function testRequiredPasswordChangeLimitsTheWorkspaceUntilDone(): void
    {
        $session = Workspace::signIn($this->settings);
        $this->pdo->exec("UPDATE users SET must_change_password = TRUE WHERE username = 'owner'");
        $login = $this->workspaceLoginResponse('owner', Workspace::PASSWORD);
        self::assertTrue($this->data($login)['passwordChangeRequired']);
        $token = $this->cookieToken($login, Cookies::WORKSPACE);
        self::assertTrue($this->data($this->send('GET', '/api/auth/session', workspace: $session))['passwordChangeRequired']);
        foreach (['/api/workspace/repositories', '/api/workspace/connections/github', '/api/workspace/preview-cache'] as $path) {
            $blocked = $this->send('GET', $path, workspace: $token);
            self::assertSame([403, 'password_change_required'], [$blocked->getStatusCode(), $this->data($blocked)['error']['code']]);
        }
        $changed = $this->send('POST', '/api/auth/password', ['currentPassword' => Workspace::PASSWORD, 'newPassword' => self::NEW_PASSWORD], workspace: $token);
        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        self::assertFalse($this->data($changed)['passwordChangeRequired']);
        self::assertSame(0, (int) $this->scalar("SELECT must_change_password FROM users WHERE username = 'owner'"));
        $blocked = $this->send('GET', '/api/workspace/repositories', workspace: $this->cookieToken($changed, Cookies::WORKSPACE));
        self::assertSame('github_not_connected', $this->data($blocked)['error']['code']);
    }

    public function testAdministratorChangesOwnPasswordAndRequiredChangeBlocksTheAdminApi(): void
    {
        (new Services($this->settings))->accounts->create('root.admin', Workspace::PASSWORD, true);
        $workspace = $this->workspaceLogin('root.admin', Workspace::PASSWORD);
        $this->pdo->exec("UPDATE users SET must_change_password = TRUE WHERE username = 'root.admin'");
        $admin = $this->cookieToken($this->send('POST', '/api/admin/auth/login', ['username' => 'root.admin', 'password' => Workspace::PASSWORD]), Cookies::ADMIN);
        self::assertTrue($this->data($this->send('GET', '/api/admin/auth/session', admin: $admin))['passwordChangeRequired']);
        $changed = $this->send('POST', '/api/admin/auth/password', ['currentPassword' => Workspace::PASSWORD, 'newPassword' => self::NEW_PASSWORD], admin: $admin);
        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        self::assertFalse($this->data($changed)['passwordChangeRequired']);
        $renewed = $this->cookieToken($changed, Cookies::ADMIN);
        self::assertSame(401, $this->send('GET', '/api/admin/auth/session', admin: $admin)->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/admin/auth/session', admin: $renewed)->getStatusCode());
        // The same account's workspace sessions end too; a workspace CSRF token never changes the admin password.
        self::assertSame(401, $this->send('GET', '/api/auth/session', workspace: $workspace)->getStatusCode());
        $crossed = $this->send(
            'POST',
            '/api/admin/auth/password',
            ['currentPassword' => self::NEW_PASSWORD, 'newPassword' => 'yet another new password'],
            admin: $renewed,
            csrf: Csrf::token(UserAuth::AUDIENCE, $renewed),
        );
        self::assertSame([403, 'csrf_failed'], [$crossed->getStatusCode(), $this->data($crossed)['error']['code']]);
    }

    private function maxAuditBefore(int $count): int
    {
        return (int) $this->scalar('SELECT COALESCE(MAX(id), 0) FROM (SELECT id FROM audit_events ORDER BY id LIMIT ' . $count . ') earlier');
    }

    private function workspaceLogin(string $username, string $password): string
    {
        return $this->cookieToken($this->workspaceLoginResponse($username, $password), Cookies::WORKSPACE);
    }

    private function workspaceLoginResponse(string $username, string $password): ResponseInterface
    {
        return $this->send('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    }

    private function cookieToken(ResponseInterface $response, string $cookie): string
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, preg_match('/^' . $cookie . '=([a-f0-9]{64});/', $response->getHeaderLine('Set-Cookie'), $match));
        return $match[1] ?? '';
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, ?string $workspace = null, ?string $admin = null, ?string $csrf = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        $cookies = [];
        if ($workspace !== null) {
            $cookies[Cookies::WORKSPACE] = $workspace;
            $csrf ??= Csrf::token(UserAuth::AUDIENCE, $workspace);
        }
        if ($admin !== null) {
            $cookies[Cookies::ADMIN] = $admin;
            $csrf ??= Csrf::token(AdminAuth::AUDIENCE, $admin);
        }
        $request = $request->withCookieParams($cookies);
        if ($csrf !== null) {
            $request = $request->withHeader('X-CSRF-Token', $csrf);
        }
        if ($body !== null) {
            $request = $request->withHeader('Origin', $this->settings->origin)->withHeader('Content-Type', 'application/json');
            $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
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

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        return array_values($read->fetchAll(PDO::FETCH_ASSOC));
    }

    private function scalar(string $sql): mixed
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        return $read->fetchColumn();
    }
}
