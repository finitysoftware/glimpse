<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Admin\AccountChanges;
use Glimpse\Application;
use Glimpse\Auth\AdminAuth;
use Glimpse\Auth\AdminContext;
use Glimpse\Auth\Csrf;
use Glimpse\Auth\UserAuth;
use Glimpse\Failure;
use Glimpse\Http\Cookies;
use Glimpse\Services;
use Glimpse\Settings;
use Glimpse\Tests\Fixtures\FixedGitLabTransports;
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\GitLabRepositoryTransport;
use Glimpse\Tests\Fixtures\RepositoryTransport;
use Glimpse\Tests\Fixtures\Workspace;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Account management in the admin panel. */
final class AdminUsersTest extends TestCase
{
    private const PASSWORD = 'admin panel test password';
    private const USER_PASSWORD = 'managed account password';
    private Settings $settings;
    private PDO $pdo;
    private RepositoryTransport $transport;
    private FixedTransports $transports;
    private string $admin;
    private int $adminId;

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
            shareEncryptionKey: base64_encode(str_repeat('k', 32)),
            githubTokenKey: Workspace::tokenKey(),
            githubTokenKeyId: Workspace::TOKEN_KEY_ID,
        );
        $this->pdo = Workspace::migrate($this->settings);
        Workspace::reset($this->pdo);
        $this->transport = new RepositoryTransport();
        $this->transports = new FixedTransports($this->transport);
        $this->adminId = (new Services($this->settings))->accounts->create('root.admin', self::PASSWORD, true);
        $login = $this->send('POST', '/api/admin/auth/login', ['username' => 'root.admin', 'password' => self::PASSWORD], session: false);
        self::assertSame(1, preg_match('/^glimpse_platform_admin=([a-f0-9]{64});/', $login->getHeaderLine('Set-Cookie'), $match));
        $this->admin = $match[1] ?? '';
    }

    public function testCreateListAndDetailNeverExposePasswordsAndRejectConflicts(): void
    {
        $created = $this->send('POST', '/api/admin/users', ['firstName' => ' Ana ', 'lastName' => 'Anić', 'email' => 'Ana@Example.com', 'username' => 'Ana.Anic',
            'password' => self::USER_PASSWORD, 'role' => 'user', 'requirePasswordChange' => true, 'githubToken' => 'fixture_token_for_ana']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $user = $this->data($created);
        self::assertSame(
            ['ana.anic', 'Ana', 'Anić', 'ana@example.com', 'user', true, true, 'connected', 'fixture-user', 1],
            [$user['username'], $user['firstName'], $user['lastName'], $user['email'], $user['role'], $user['active'], $user['mustChangePassword'],
                $user['connection']['status'], $user['connection']['login'], $user['version']],
        );
        foreach ([self::USER_PASSWORD, 'fixture_token_for_ana', 'password', '$argon2', '$2y$'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $created->getBody());
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM audit_events WHERE actor_user_id = ' . $this->adminId));

        foreach ([[['username' => 'ana.anic'], 409, 'username_taken'], [['username' => 'other', 'email' => 'ana@example.com'], 409, 'email_taken'],
            [['username' => 'other', 'email' => 'not an email'], 400, 'invalid_email'], [['username' => 'other', 'password' => 'short'], 400, 'invalid_password'],
            [['username' => 'other', 'firstName' => ''], 400, 'invalid_input'], [['username' => 'x'], 400, 'invalid_username'],
            [['username' => 'other', 'role' => 'owner'], 400, 'invalid_input']] as [$change, $status, $code]) {
            $rejected = $this->send('POST', '/api/admin/users', $change + ['firstName' => 'Other', 'lastName' => 'User', 'password' => self::USER_PASSWORD]);
            self::assertSame([$status, $code], [$rejected->getStatusCode(), $this->data($rejected)['error']['code']]);
        }
        // A rejected GitHub token creates nothing.
        $this->transport->status = 401;
        $rejected = $this->send('POST', '/api/admin/users', ['firstName' => 'Token', 'lastName' => 'Rejected', 'username' => 'token.rejected', 'password' => self::USER_PASSWORD, 'githubToken' => 'bad_token']);
        self::assertSame([422, 'github_token_rejected'], [$rejected->getStatusCode(), $this->data($rejected)['error']['code']]);
        self::assertFalse($this->scalar("SELECT id FROM users WHERE username = 'token.rejected'"));
        $this->transport->status = 200;

        foreach (range(1, 52) as $number) {
            Workspace::user($this->pdo, sprintf('bulk%02d', $number));
        }
        $page = $this->data($this->send('GET', '/api/admin/users?sort=username'));
        self::assertSame([50, 2, 54], [count($page['items']), $page['nextPage'], $page['total']]);
        self::assertSame(['ana.anic', 'bulk01'], [$page['items'][0]['username'], $page['items'][1]['username']]);
        self::assertSame(['ana.anic'], array_column($this->data($this->send('GET', '/api/admin/users?q=ani%C4%87'))['items'], 'username'));
        self::assertSame(['root.admin'], array_column($this->data($this->send('GET', '/api/admin/users?role=admin'))['items'], 'username'));
        self::assertSame(['ana.anic'], array_column($this->data($this->send('GET', '/api/admin/users?github=connected'))['items'], 'username'));
        self::assertSame(53, $this->data($this->send('GET', '/api/admin/users?github=not_configured'))['total']);
        self::assertSame(['id', 'username', 'firstName', 'lastName', 'email', 'role', 'active', 'version', 'github', 'gitlab', 'links', 'lastSignInAt', 'createdAt'], array_keys($page['items'][0]));
        self::assertSame(400, $this->send('GET', '/api/admin/users?sort=password_hash')->getStatusCode());
        self::assertSame(404, $this->send('GET', '/api/admin/users/999999')->getStatusCode());
        self::assertSame(404, $this->send('GET', '/api/admin/users/abc')->getStatusCode());
        self::assertSame(401, $this->send('GET', '/api/admin/users', session: false)->getStatusCode());
    }

    public function testUpdateUsesTheVersionAndProtectsOwnAccountAndTheLastAdmin(): void
    {
        $user = $this->create('member.one');
        $session = $this->workspaceLogin('member.one', self::USER_PASSWORD);
        $renamed = $this->send('PATCH', '/api/admin/users/' . $user['id'], ['expectedVersion' => $user['version'], 'firstName' => 'Mia', 'lastName' => 'Marić',
            'email' => 'mia@example.com', 'username' => 'mia.maric', 'password' => '']);
        self::assertSame(200, $renamed->getStatusCode(), (string) $renamed->getBody());
        $renamed = $this->data($renamed);
        self::assertSame(['mia.maric', 'Mia', 'Marić', 'mia@example.com', 2], [$renamed['username'], $renamed['firstName'], $renamed['lastName'], $renamed['email'], $renamed['version']]);
        // An empty password keeps the old one and the user stays signed in.
        self::assertSame(200, $this->workspaceSession($session)->getStatusCode());
        $stale = $this->send('PATCH', '/api/admin/users/' . $user['id'], ['expectedVersion' => $user['version'], 'firstName' => 'Late']);
        self::assertSame([409, 'user_changed'], [$stale->getStatusCode(), $this->data($stale)['error']['code']]);

        $password = $this->data($this->send('PATCH', '/api/admin/users/' . $user['id'], ['expectedVersion' => 2, 'password' => 'a password set by the admin', 'requirePasswordChange' => true]));
        self::assertTrue($password['mustChangePassword']);
        self::assertSame(401, $this->workspaceSession($session)->getStatusCode());
        self::assertSame(401, $this->workspaceLoginResponse('mia.maric', self::USER_PASSWORD)->getStatusCode());
        self::assertTrue($this->data($this->workspaceLoginResponse('mia.maric', 'a password set by the admin'))['passwordChangeRequired']);

        $promoted = $this->data($this->send('PATCH', '/api/admin/users/' . $user['id'], ['expectedVersion' => $password['version'], 'role' => 'admin']));
        self::assertSame('admin', $promoted['role']);
        $disabled = $this->data($this->send('PATCH', '/api/admin/users/' . $user['id'], ['expectedVersion' => $promoted['version'], 'role' => 'user', 'active' => false]));
        self::assertSame(['user', false], [$disabled['role'], $disabled['active']]);
        self::assertSame(401, $this->workspaceLoginResponse('mia.maric', 'a password set by the admin')->getStatusCode());
        self::assertTrue($this->data($this->send('PATCH', '/api/admin/users/' . $user['id'], ['expectedVersion' => $disabled['version'], 'active' => true]))['active']);

        $self = $this->data($this->send('GET', '/api/admin/users/' . $this->adminId));
        foreach ([['active' => false], ['role' => 'user']] as $change) {
            $forbidden = $this->send('PATCH', '/api/admin/users/' . $this->adminId, $change + ['expectedVersion' => $self['version']]);
            self::assertSame([422, 'self_action_forbidden'], [$forbidden->getStatusCode(), $this->data($forbidden)['error']['code']]);
        }
        self::assertSame('Root', $this->data($this->send('PATCH', '/api/admin/users/' . $this->adminId, ['expectedVersion' => $self['version'], 'firstName' => 'Root']))['firstName']);

        // Two administrators demoting each other at the same time: the one whose own role is already gone cannot remove the last one.
        $other = $this->create('second.admin', 'admin');
        $service = (new Services($this->settings, $this->transports, new FixedGitLabTransports(new GitLabRepositoryTransport())))->users;
        $context = new AdminContext($this->adminId, 'root.admin', null, null, null, 0, 1, false, '');
        $this->pdo->exec("DELETE FROM user_roles WHERE role = 'platform_admin' AND user_id = " . $this->adminId);
        try {
            $service->update($context, $other['id'], AccountChanges::fromBody(['expectedVersion' => $other['version'], 'role' => 'user']));
            self::fail('The last administrator was demoted.');
        } catch (Failure $error) {
            self::assertSame('last_admin', $error->errorCode);
        }
        try {
            $service->delete($context, $other['id'], $other['version']);
            self::fail('The last administrator was deleted.');
        } catch (Failure $error) {
            self::assertSame('last_admin', $error->errorCode);
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM audit_events WHERE actor_user_id = ' . $this->adminId));
    }

    public function testSignOutEverywhereAndDeleteRemoveEverythingOfTheAccount(): void
    {
        $session = Workspace::signIn($this->settings, 'dana', github: $this->transports);
        $danaId = (int) $this->scalar("SELECT id FROM users WHERE username = 'dana'");
        $link = $this->send('POST', '/api/workspace/repositories/fixture/alpha/shares', ['name' => 'Review', 'branches' => ['main'],
            'visibility' => [['branch' => 'main', 'allFiles' => true]]], workspace: $session);
        self::assertSame(201, $link->getStatusCode(), (string) $link->getBody());
        $data = $this->data($link);
        $exchange = $this->send('POST', '/api/shares/' . $data['publicId'] . '/exchange', ['secret' => explode('#', (string) $data['url'])[1]], session: false);
        self::assertSame(1, preg_match('/glimpse_visitor=([a-f0-9]{64})/', $exchange->getHeaderLine('Set-Cookie'), $visitor));
        $browse = $this->send('GET', '/api/shares/' . $data['publicId'] . '/browse', session: false, visitor: $visitor[1] ?? '');
        self::assertSame(200, $browse->getStatusCode(), (string) $browse->getBody());
        $detail = $this->data($this->send('GET', '/api/admin/users/' . $danaId));
        self::assertSame([1, 1, 1, 1], [$detail['links']['active'], $detail['repositories']['total'], $detail['linkStates']['opens30Days'], $detail['sessions']['workspace']]);
        self::assertGreaterThan(0, $detail['cache']['entries']);

        $signedOut = $this->data($this->send('POST', '/api/admin/users/' . $danaId . '/sessions/revoke', []));
        self::assertSame(0, $signedOut['sessions']['workspace']);
        self::assertSame(401, $this->workspaceSession($session)->getStatusCode());

        $stale = $this->send('POST', '/api/admin/users/' . $danaId . '/delete', ['expectedVersion' => $detail['version']]);
        self::assertSame([409, 'user_changed'], [$stale->getStatusCode(), $this->data($stale)['error']['code']]);
        $own = $this->send('POST', '/api/admin/users/' . $this->adminId . '/delete', ['expectedVersion' => 1]);
        self::assertSame([422, 'self_action_forbidden'], [$own->getStatusCode(), $this->data($own)['error']['code']]);
        self::assertSame(200, $this->send('POST', '/api/admin/users/' . $danaId . '/delete', ['expectedVersion' => $signedOut['version']])->getStatusCode());

        foreach (['users WHERE id', 'user_roles WHERE user_id', 'github_connections WHERE user_id', 'repositories WHERE user_id', 'share_links WHERE user_id',
            'share_branch_policies WHERE user_id', 'sessions WHERE user_id', 'api_cache WHERE user_id', 'api_cache_state WHERE user_id', 'daily_metrics WHERE user_id',
            'audit_events WHERE subject_user_id', 'audit_events WHERE actor_user_id'] as $table) {
            self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ' . $table . ' = ' . $danaId), $table);
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM session_shares'));
        self::assertSame(0, (int) $this->scalar('SELECT reserved_bytes FROM cache_capacity_state'));
        $gone = $this->send('GET', '/api/shares/' . $data['publicId'] . '/browse', session: false, visitor: $visitor[1] ?? '');
        self::assertSame([404, 'share_unavailable'], [$gone->getStatusCode(), $this->data($gone)['error']['code']]);
        self::assertSame(404, $this->send('GET', '/api/admin/users/' . $danaId)->getStatusCode());
        // The username is free again; the new account starts empty.
        self::assertSame(0, $this->create('dana')['links']['total']);
    }

    public function testRequiredPasswordChangeBlocksAccountManagement(): void
    {
        $this->pdo->exec('UPDATE users SET must_change_password = TRUE WHERE id = ' . $this->adminId);
        $blocked = $this->send('GET', '/api/admin/users');
        self::assertSame([403, 'password_change_required'], [$blocked->getStatusCode(), $this->data($blocked)['error']['code']]);
    }

    /** @return array<string, mixed> */
    private function create(string $username, string $role = 'user'): array
    {
        $response = $this->send('POST', '/api/admin/users', ['firstName' => 'Test', 'lastName' => 'User', 'username' => $username, 'password' => self::USER_PASSWORD, 'role' => $role]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        return $this->data($response);
    }

    private function workspaceLogin(string $username, string $password): string
    {
        $response = $this->workspaceLoginResponse($username, $password);
        self::assertSame(1, preg_match('/^glimpse_workspace=([a-f0-9]{64});/', $response->getHeaderLine('Set-Cookie'), $match));
        return $match[1] ?? '';
    }

    private function workspaceLoginResponse(string $username, string $password): ResponseInterface
    {
        return $this->send('POST', '/api/auth/login', ['username' => $username, 'password' => $password], session: false);
    }

    private function workspaceSession(string $token): ResponseInterface
    {
        return $this->send('GET', '/api/auth/session', session: false, workspace: $token);
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, bool $session = true, ?string $workspace = null, ?string $visitor = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);
        $cookies = [];
        if ($session) {
            $cookies[Cookies::ADMIN] = $this->admin;
            $request = $request->withHeader('X-CSRF-Token', Csrf::token(AdminAuth::AUDIENCE, $this->admin));
        }
        if ($workspace !== null) {
            $cookies[Cookies::WORKSPACE] = $workspace;
            $request = $request->withHeader('X-CSRF-Token', Csrf::token(UserAuth::AUDIENCE, $workspace));
        }
        if ($visitor !== null) {
            $cookies[Cookies::VISITOR] = $visitor;
        }
        $request = $request->withCookieParams($cookies);
        if ($body !== null) {
            $request = $request->withHeader('Origin', $this->settings->origin)->withHeader('Content-Type', 'application/json');
            $request->getBody()->write(json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR));
            $request->getBody()->rewind();
        }
        return Application::create($this->settings, $this->transports)->handle($request);
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
