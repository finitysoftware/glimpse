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
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\RepositoryTransport;
use Glimpse\Tests\Fixtures\Workspace;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Platform numbers for the admin dashboard. */
final class AdminDashboardTest extends TestCase
{
    private const PASSWORD = 'admin panel test password';
    private Settings $settings;
    private PDO $pdo;
    private FixedTransports $transports;

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
        $this->transports = new FixedTransports(new RepositoryTransport());
    }

    public function testDashboardCountsThePlatformWithoutNamesOrSecrets(): void
    {
        $users = (new Services($this->settings))->accounts;
        $users->create('root.admin', self::PASSWORD, true);
        $dana = Workspace::signIn($this->settings, 'dana', github: $this->transports);
        $users->create('idle.user', self::PASSWORD);
        $users->create('gone.user', self::PASSWORD);
        $users->disable('gone.user');
        $kept = $this->link($dana, 'Kept');
        $revoked = $this->link($dana, 'Revoked');
        $this->send('POST', '/api/workspace/shares/' . $revoked['publicId'] . '/revoke', [], workspace: $dana);
        $exchange = $this->send('POST', '/api/shares/' . $kept['publicId'] . '/exchange', ['secret' => $kept['secret']]);
        self::assertSame(200, $exchange->getStatusCode(), (string) $exchange->getBody());
        $danaId = (int) $this->scalar("SELECT id FROM users WHERE username = 'dana'");
        $this->pdo->exec("INSERT INTO daily_metrics (day, metric, user_id, count) VALUES
            (DATE_SUB(UTC_DATE(), INTERVAL 3 DAY), 'link_opened', $danaId, 4), (DATE_SUB(UTC_DATE(), INTERVAL 20 DAY), 'link_opened', $danaId, 9)");

        $admin = $this->adminLogin();
        $response = $this->send('GET', '/api/admin/dashboard', admin: $admin);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertSame(['total' => 4, 'active' => 3, 'disabled' => 1, 'admins' => 1, 'signedInLast7Days' => 2, 'withoutGitHub' => 3, 'withoutGitLab' => 4], $data['users']);
        self::assertSame(['connected' => 1, 'notVerified' => 0, 'credentialsRejected' => 0, 'accessRestricted' => 0, 'temporarilyUnavailable' => 0, 'notConfigured' => 3], $data['github']);
        self::assertSame(['active' => 1, 'expired' => 0, 'revoked' => 1, 'selectedFiles' => 0, 'history' => 0], $data['links']);
        self::assertSame(['activeSessions' => 1, 'opensLast7Days' => 5, 'opensLast30Days' => 14], $data['visitors']);
        self::assertGreaterThan(0, $data['cache']['entries']);
        self::assertSame($this->settings->cacheBudgetBytes, $data['cache']['maxBytes']);
        $system = $data['system'];
        self::assertSame(
            [$system['migrations']['total'], '009_gitlab.sql', [], false, true, Workspace::TOKEN_KEY_ID],
            [$system['migrations']['applied'], $system['migrations']['latest'], $system['migrations']['pending'], $system['maintenance'],
                $system['shareKeyConfigured'], $system['githubTokenKeyId']],
        );
        self::assertSame(PHP_VERSION, $system['phpVersion']);

        self::assertCount(14, $data['series']);
        $today = $data['series'][13];
        self::assertSame([(string) $this->scalar('SELECT UTC_DATE()'), 1, 1, 2], [$today['day'], $today['workspaceLogins'], $today['linkOpens'], $today['linksCreated']]);
        self::assertSame(4, $data['series'][10]['linkOpens']);
        self::assertSame(5, array_sum(array_column($data['series'], 'linkOpens')));
        foreach (['dana', 'idle.user', 'fixture/alpha', 'Kept', $kept['secret'], Workspace::token('dana')] as $private) {
            self::assertStringNotContainsString($private, (string) $response->getBody());
        }
        self::assertSame(401, $this->send('GET', '/api/admin/dashboard', workspace: $dana)->getStatusCode());
    }

    private function adminLogin(): string
    {
        $login = $this->send('POST', '/api/admin/auth/login', ['username' => 'root.admin', 'password' => self::PASSWORD]);
        self::assertSame(1, preg_match('/^glimpse_platform_admin=([a-f0-9]{64});/', $login->getHeaderLine('Set-Cookie'), $match));
        return $match[1] ?? '';
    }

    /** @return array{publicId: string, secret: string} */
    private function link(string $session, string $name): array
    {
        $response = $this->send('POST', '/api/workspace/repositories/fixture/alpha/shares', ['name' => $name, 'branches' => ['main'],
            'visibility' => [['branch' => 'main', 'allFiles' => true]]], workspace: $session);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        return ['publicId' => (string) $data['publicId'], 'secret' => explode('#', (string) $data['url'])[1]];
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, ?string $admin = null, ?string $workspace = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        $cookies = [];
        if ($admin !== null) {
            $cookies[Cookies::ADMIN] = $admin;
            $request = $request->withHeader('X-CSRF-Token', Csrf::token(AdminAuth::AUDIENCE, $admin));
        }
        if ($workspace !== null) {
            $cookies[Cookies::WORKSPACE] = $workspace;
            $request = $request->withHeader('X-CSRF-Token', Csrf::token(UserAuth::AUDIENCE, $workspace));
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
