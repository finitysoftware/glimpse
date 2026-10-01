<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Accounts\Passwords;
use Glimpse\Application;
use Glimpse\Auth\Csrf;
use Glimpse\Auth\UserAuth;
use Glimpse\Failure;
use Glimpse\FailureKind;
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

/** Schema 008 and the account, GitHub and metrics operations the admin panel builds on. */
final class AdminFoundationTest extends TestCase
{
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

    public function testSchemaAddsProfileVersionAndMetricsWithoutChangingExistingAccounts(): void
    {
        $id = Workspace::user($this->pdo, 'existing');
        $row = $this->row('SELECT first_name, last_name, email, version, must_change_password FROM users WHERE id = ' . $id);
        self::assertSame([null, null, null, 1, 0], [$row['first_name'], $row['last_name'], $row['email'], (int) $row['version'], (int) $row['must_change_password']]);
        foreach (['Upper@Example.com', 'no-at-sign'] as $email) {
            try {
                $this->pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $id]);
                self::fail('The email check accepted ' . $email);
            } catch (\PDOException) {
                self::assertNull($this->scalar('SELECT email FROM users WHERE id = ' . $id));
            }
        }
        // A deleted account takes its counters with it, and the migration checkpoint no longer blocks deleting the imported owner.
        Workspace::services($this->settings, $this->pdo)->metrics->increment('workspace_login', $id);
        self::assertSame('SET NULL', $this->scalar("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'migration_checkpoint_owner'"));
        $this->pdo->exec('DELETE FROM user_roles WHERE user_id = ' . $id);
        $this->pdo->exec('DELETE FROM users WHERE id = ' . $id);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM daily_metrics'));
    }

    public function testDailyMetricsCountSignInsCreatedAndOpenedLinksPerOwner(): void
    {
        $owner = Workspace::signIn($this->settings, github: $this->transports);
        $ownerId = (int) $this->scalar("SELECT id FROM users WHERE username = 'owner'");
        $this->login('owner');
        $link = $this->send('POST', '/api/workspace/repositories/fixture/alpha/shares', ['name' => 'Review', 'branches' => ['main'],
            'visibility' => [['branch' => 'main', 'allFiles' => true]]], $owner);
        self::assertSame(201, $link->getStatusCode(), (string) $link->getBody());
        $data = $this->data($link);
        foreach ([1, 2] as $ignored) {
            $exchange = $this->send('POST', '/api/shares/' . $data['publicId'] . '/exchange', ['secret' => explode('#', (string) $data['url'])[1]]);
            self::assertSame(200, $exchange->getStatusCode(), (string) $exchange->getBody());
        }
        self::assertSame([['link_created', 1], ['link_opened', 2], ['workspace_login', 2]], array_map(
            static fn(array $row): array => [$row['metric'], (int) $row['count']],
            $this->rows('SELECT metric, count FROM daily_metrics WHERE user_id = ' . $ownerId . ' AND day = UTC_DATE() ORDER BY CAST(metric AS CHAR)'),
        ));
        $this->pdo->exec("INSERT INTO daily_metrics (day, metric, user_id, count) VALUES (DATE_SUB(UTC_DATE(), INTERVAL 401 DAY), 'link_opened', $ownerId, 5),
            (DATE_SUB(UTC_DATE(), INTERVAL 400 DAY), 'link_opened', $ownerId, 7)");
        self::assertSame(1, Workspace::services($this->settings, $this->pdo)->metrics->purgeExpired());
        self::assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM daily_metrics'));
    }

    public function testAccountOperationsByIdComposeInOneTransactionWithoutAuditEvents(): void
    {
        $pdo = $this->pdo;
        $pdo->beginTransaction();
        $id = Workspace::services($this->settings, $pdo)->accounts->insertAccount(
            'ana',
            Passwords::hash('first account password'),
            false,
            ['firstName' => 'Ana', 'lastName' => 'Anić', 'email' => 'ana@example.com'],
            true,
        );
        $pdo->commit();
        $row = $this->row('SELECT first_name, last_name, email, version, must_change_password, status FROM users WHERE id = ' . $id);
        self::assertSame(
            ['Ana', 'Anić', 'ana@example.com', 1, 1, 'active'],
            [$row['first_name'], $row['last_name'], $row['email'], (int) $row['version'], (int) $row['must_change_password'], $row['status']],
        );
        self::assertSame(['member'], array_column($this->rows('SELECT role FROM user_roles WHERE user_id = ' . $id), 'role'));
        foreach ([['ana', 'other@example.com', 'username_taken'], ['ana2', 'ana@example.com', 'email_taken']] as [$username, $email, $code]) {
            $pdo->beginTransaction();
            try {
                Workspace::services($this->settings, $pdo)->accounts->insertAccount($username, Passwords::hash('another account password'), false, ['email' => $email]);
                self::fail('Duplicate account was created.');
            } catch (Failure $error) {
                self::assertSame([FailureKind::Conflict, $code], [$error->kind, $error->errorCode]);
            } finally {
                $pdo->rollBack();
            }
        }

        $session = (new Services($this->settings))->auth->login('ana', 'first account password', 'fixture')->token;
        $pdo->beginTransaction();
        $locked = Workspace::services($this->settings, $pdo)->accounts->lock($id);
        Workspace::services($this->settings, $pdo)->accounts->setPassword($id, Passwords::hash('second account password'), false);
        self::assertTrue(Workspace::services($this->settings, $pdo)->accounts->setPlatformAdmin($id, true));
        self::assertFalse(Workspace::services($this->settings, $pdo)->accounts->setPlatformAdmin($id, true));
        $pdo->commit();
        self::assertSame(['ana', 'active', 1], [$locked['username'], $locked['status'], $locked['version']]);
        self::assertSame([3, 2, 0], array_map('intval', array_values($this->row('SELECT version, session_version, must_change_password FROM users WHERE id = ' . $id))));
        self::assertSame(401, $this->send('GET', '/api/auth/session', session: $session)->getStatusCode());
        self::assertSame(200, $this->login('ana', 'second account password')->getStatusCode());

        $pdo->beginTransaction();
        self::assertTrue(Workspace::services($this->settings, $pdo)->accounts->setActive($id, false));
        self::assertFalse(Workspace::services($this->settings, $pdo)->accounts->setActive($id, false));
        $pdo->commit();
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM sessions WHERE user_id = ' . $id . ' AND revoked_at IS NULL'));
        self::assertSame(401, $this->login('ana', 'second account password')->getStatusCode());
        $pdo->beginTransaction();
        self::assertTrue(Workspace::services($this->settings, $pdo)->accounts->setActive($id, true));
        self::assertTrue(Workspace::services($this->settings, $pdo)->accounts->setPlatformAdmin($id, false));
        $pdo->commit();
        $session = $this->data($this->login('ana', 'second account password'));
        self::assertSame('ana', $session['user']['username']);
        $pdo->beginTransaction();
        Workspace::services($this->settings, $pdo)->accounts->revokeSessions($id);
        $pdo->commit();
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM sessions WHERE user_id = ' . $id . ' AND revoked_at IS NULL'));
        self::assertSame(7, (int) $this->scalar('SELECT version FROM users WHERE id = ' . $id));
        // Only sign-ins are audited; the account operations themselves write nothing.
        self::assertSame(['login_failed', 'login_succeeded'], array_column($this->rows('SELECT DISTINCT action FROM audit_events ORDER BY action'), 'action'));
        $this->expectException(Failure::class);
        $pdo->beginTransaction();
        try {
            Workspace::services($this->settings, $pdo)->accounts->lock($id + 1000);
        } finally {
            $pdo->rollBack();
        }
    }

    public function testCliOperationsKeepTheirAuditAndAlsoAdvanceTheVersion(): void
    {
        $users = (new Services($this->settings))->accounts;
        $id = $users->create('cli.user', 'cli account password');
        self::assertTrue($users->disable('cli.user'));
        self::assertFalse($users->disable('cli.user'));
        self::assertTrue($users->grantPlatformAdmin('cli.user'));
        self::assertSame(3, (int) $this->scalar('SELECT version FROM users WHERE id = ' . $id));
        self::assertSame(['user_created', 'user_disabled', 'user_role_granted'], array_column($this->rows('SELECT action FROM audit_events ORDER BY id'), 'action'));
    }

    public function testAdministratorCanRevealReplaceAndRemoveAUsersTokenWithoutAuditEvents(): void
    {
        $session = Workspace::signIn($this->settings, 'dana', github: $this->transports);
        $id = (int) $this->scalar("SELECT id FROM users WHERE username = 'dana'");
        $service = (new Services($this->settings, github: $this->transports))->github;
        self::assertSame(Workspace::token('dana'), $service->reveal($id));
        $before = (int) $this->scalar('SELECT COUNT(*) FROM audit_events');
        (new Services($this->settings))->accounts->disable('dana');
        $before++;
        $replaced = $service->replaceFor($id, 'fixture_token_replaced_by_admin', 1, 'admin-fixture');
        self::assertSame(['connected', 2], [$replaced->status, $replaced->credentialVersion]);
        self::assertSame('fixture_token_replaced_by_admin', $service->reveal($id));
        try {
            $service->replaceFor($id, 'fixture_token_stale_version', 1, 'admin-fixture');
            self::fail('A stale connection version replaced the token.');
        } catch (Failure $error) {
            self::assertSame('connection_changed', $error->errorCode);
        }
        try {
            $service->replaceFor($id + 1000, 'fixture_token_for_nobody', 0, 'admin-fixture');
            self::fail('A token was saved for a missing account.');
        } catch (Failure $error) {
            self::assertSame('user_not_found', $error->errorCode);
        }

        self::assertTrue($service->remove($id));
        self::assertFalse($service->remove($id));
        self::assertNull($service->reveal($id));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM api_cache WHERE user_id = ' . $id));
        (new Services($this->settings))->accounts->enable('dana');
        $before++;
        // A token saved after removal starts a newer credential generation instead of reusing version 1.
        self::assertSame(3, $service->replaceFor($id, 'fixture_token_after_removal', 0, 'admin-fixture')->credentialVersion);
        self::assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM audit_events'));
        self::assertNotSame('', $session);
    }

    private function login(string $username, string $password = Workspace::PASSWORD): ResponseInterface
    {
        return $this->send('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, ?string $session = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($session !== null) {
            $request = $request->withCookieParams([Cookies::WORKSPACE => $session])->withHeader('X-CSRF-Token', Csrf::token(UserAuth::AUDIENCE, $session));
        }
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

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
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
