<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Application;
use Glimpse\Auth\UserContext;
use Glimpse\Connections\ConnectionStatus;
use Glimpse\Database\Connection;
use Glimpse\Failure;
use Glimpse\GitHub\GitHubConnectionService;
use Glimpse\Http\Cookies;
use Glimpse\Services;
use Glimpse\Settings;
use Glimpse\Source\HttpResult;
use Glimpse\Source\TokenCipher;
use Glimpse\Tests\Fixtures\FakeTransport;
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\RepositoryTransport;
use Glimpse\Tests\Fixtures\Workspace;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class GitHubConnectionTest extends TestCase
{
    private const TOKEN = 'fixture_token_0123456789abcdefg';
    private Settings $settings;
    private PDO $pdo;
    private UserContext $user;
    private string $session;

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
        $this->pdo = (new Connection($this->settings))->get();
        Workspace::migrate($this->settings);
        Workspace::reset($this->pdo);
        $this->session = Workspace::signIn($this->settings);
        $this->user = (new Services($this->settings))->auth->authenticate($this->session);
    }

    public function testMissingConnectionIsReportedWithoutGitHubCalls(): void
    {
        $transport = new FakeTransport([]);
        self::assertSame(['configured' => false, 'status' => 'not_configured', 'maskedToken' => null, 'credentialVersion' => 0,
            'githubLogin' => null, 'lastCheckedAt' => null, 'errorCode' => null], $this->data($this->send('GET', '/api/workspace/connections/github', transport: $transport)));
        self::assertSame(['configured' => false, 'status' => 'not_configured', 'credentialVersion' => 0], $this->data($this->send('GET', '/api/auth/session', transport: $transport))['github']);
        $catalogue = $this->send('GET', '/api/workspace/repositories', transport: $transport);
        self::assertSame([409, 'github_not_connected'], [$catalogue->getStatusCode(), $this->data($catalogue)['error']['code']]);
        self::assertSame(409, $this->send('POST', '/api/workspace/connections/github/probe', [], transport: $transport)->getStatusCode());
        self::assertSame([], $transport->paths);
    }

    public function testSaveVerifiesMasksAndEncryptsWithoutReturningTheToken(): void
    {
        $response = $this->send('PUT', '/api/workspace/connections/github', ['token' => self::TOKEN, 'expectedVersion' => 0], transport: new RepositoryTransport());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertSame('fixture_token_0' . str_repeat('*', 16), $data['maskedToken']);
        self::assertSame(15, strlen(rtrim($data['maskedToken'], '*')));
        self::assertSame(['connected', 1, 'fixture-user', true], [$data['status'], $data['credentialVersion'], $data['githubLogin'], $data['configured']]);
        self::assertStringNotContainsString(self::TOKEN, (string) $response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $row = $this->row('SELECT id, encrypted_token, github_user_id FROM github_connections');
        self::assertStringNotContainsString(substr(self::TOKEN, 0, 12), (string) $row['encrypted_token']);
        self::assertSame(self::TOKEN, (new TokenCipher(Workspace::tokenKey(), Workspace::TOKEN_KEY_ID))
            ->decrypt($this->user->userId, (int) $row['id'], Workspace::TOKEN_KEY_ID, (string) $row['encrypted_token']));
        self::assertSame(4242, (int) $row['github_user_id']);
        self::assertSame(['configured' => true, 'status' => 'connected', 'credentialVersion' => 1], $this->data($this->send('GET', '/api/auth/session'))['github']);
        self::assertSame('abcdefgh********', GitHubConnectionService::mask('abcdefgh12345678'));
        self::assertSame('abcd*****', GitHubConnectionService::mask('abcd12345'));
        $audit = (string) json_encode($this->column("SELECT CONCAT_WS('|', action, COALESCE(metadata, '')) FROM audit_events"));
        self::assertStringContainsString('github_token_configured', $audit);
        foreach ([self::TOKEN, substr(self::TOKEN, 0, 15)] as $secret) {
            self::assertStringNotContainsString($secret, $audit);
        }
    }

    public function testRejectedRestrictedOrUnavailableCandidatesKeepThePreviousConnection(): void
    {
        $this->save(self::TOKEN, 0, new RepositoryTransport());
        $before = $this->row('SELECT encrypted_token, credential_version, last_check_status FROM github_connections');
        $user = static fn(int $status): HttpResult => new HttpResult($status, [], '{"message":"private"}');
        foreach ([[new FakeTransport([$user(401)]), 422, 'github_token_rejected'], [new FakeTransport([$user(403)]), 422, 'github_token_restricted'],
            [new FakeTransport([Failure::upstream('github_timeout', 'GitHub did not respond in time.')]), 502, 'github_timeout'],
            [new FakeTransport([new HttpResult(429, ['retry-after' => '60'], '{}')]), 429, 'github_rate_limited']] as [$transport, $status, $code]) {
            $response = $this->send('PUT', '/api/workspace/connections/github', ['token' => 'replacement_token_value', 'expectedVersion' => 1], transport: $transport);
            self::assertSame([$status, $code], [$response->getStatusCode(), $this->data($response)['error']['code']]);
            self::assertSame($before, $this->row('SELECT encrypted_token, credential_version, last_check_status FROM github_connections'));
        }
        foreach ([['token' => 'with space', 'expectedVersion' => 1], ['token' => str_repeat('t', 1025), 'expectedVersion' => 1]] as $body) {
            self::assertSame(422, $this->send('PUT', '/api/workspace/connections/github', $body, transport: new FakeTransport([]))->getStatusCode());
        }
        self::assertSame(400, $this->send('PUT', '/api/workspace/connections/github', ['token' => 'x', 'expectedVersion' => '1'])->getStatusCode());
        self::assertSame(403, $this->send('PUT', '/api/workspace/connections/github', ['token' => 'x', 'expectedVersion' => 1], csrf: 'wrong')->getStatusCode());
    }

    public function testEmptyCatalogueIsAValidToken(): void
    {
        $transport = new FakeTransport([new HttpResult(200, [], '{"id":7,"login":"empty"}'), new HttpResult(200, [], '[]')]);
        self::assertSame('connected', $this->save(self::TOKEN, 0, $transport)->status);
        self::assertSame(['/user', '/user/repos?per_page=30&page=1&sort=full_name&direction=asc'], $transport->paths);
    }

    public function testStaleOrConcurrentSavesNeverOverwriteANewerToken(): void
    {
        $this->save(self::TOKEN, 0, new RepositoryTransport());
        $stale = $this->send('PUT', '/api/workspace/connections/github', ['token' => 'stale_token_value', 'expectedVersion' => 0], transport: new FakeTransport([]));
        self::assertSame([409, 'connection_changed'], [$stale->getStatusCode(), $this->data($stale)['error']['code']]);
        // Another tab saves while this candidate is being verified.
        $racing = new RepositoryTransport();
        $racing->beforeRequest = function () use ($racing): void {
            $racing->beforeRequest = null;
            $this->pdo->exec('UPDATE github_connections SET credential_version = credential_version + 1');
        };
        $response = $this->send('PUT', '/api/workspace/connections/github', ['token' => 'racing_token_value', 'expectedVersion' => 1], transport: $racing);
        self::assertSame([409, 'connection_changed'], [$response->getStatusCode(), $this->data($response)['error']['code']]);
        self::assertSame(2, (int) $this->row('SELECT credential_version FROM github_connections')['credential_version']);
        $replaced = $this->save('replacement_token_value', 2, new RepositoryTransport());
        self::assertSame([3, 'replacement' . str_repeat('*', 12)], [$replaced->credentialVersion, $replaced->maskedToken]);
    }

    public function testReplacementClearsOnlyThisUsersCacheAndProbeClassifiesFailures(): void
    {
        $this->save(self::TOKEN, 0, new RepositoryTransport());
        $other = Workspace::user($this->pdo, 'other');
        $cache = $this->pdo->prepare("INSERT INTO api_cache (cache_key, user_id, credential_version, scope_key, payload, payload_bytes, created_at, expires_at)
            VALUES (?, ?, 1, ?, '{}', 2, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR))");
        $cache->execute([random_bytes(32), $this->user->userId, random_bytes(32)]);
        $cache->execute([random_bytes(32), $other, random_bytes(32)]);
        $this->save('replacement_token_value', 1, new RepositoryTransport());
        self::assertSame([$other], array_map('intval', $this->column('SELECT user_id FROM api_cache')));
        self::assertSame(1, (int) $this->row("SELECT COUNT(*) AS n FROM audit_events WHERE action = 'github_token_replaced'")['n']);

        foreach ([[401, 'credentials_rejected', 'github_credentials_rejected'], [403, 'access_restricted', 'github_access_denied'], [500, 'temporarily_unavailable', 'github_unavailable']] as [$status, $state, $code]) {
            $this->pdo->exec('DELETE FROM auth_throttles');
            $probe = $this->send('POST', '/api/workspace/connections/github/probe', [], transport: new FakeTransport([new HttpResult($status, [], '{}'), new HttpResult($status, [], '{}')]));
            self::assertSame(200, $probe->getStatusCode(), (string) $probe->getBody());
            self::assertSame([$state, $code, 2], [$this->data($probe)['status'], $this->data($probe)['errorCode'], $this->data($probe)['credentialVersion']]);
        }
        $limited = $this->send('POST', '/api/workspace/connections/github/probe', [], transport: new FakeTransport([new HttpResult(429, ['retry-after' => '30'], '{}')]));
        self::assertSame([429, '30'], [$limited->getStatusCode(), $limited->getHeaderLine('Retry-After')]);
        self::assertSame('temporarily_unavailable', $this->row('SELECT last_check_status FROM github_connections')['last_check_status']);
        self::assertSame('connected', $this->data($this->send('POST', '/api/workspace/connections/github/probe', [], transport: new RepositoryTransport()))['status']);
    }

    public function testUnreadableTokenIsAServerErrorNotAMissingConnection(): void
    {
        $this->save(self::TOKEN, 0, new RepositoryTransport());
        $this->settings = new Settings(
            environment: 'test',
            origin: $this->settings->origin,
            database: $this->settings->database,
            githubTokenKey: base64_encode(str_repeat('w', 32)),
            githubTokenKeyId: Workspace::TOKEN_KEY_ID,
        );
        $transport = new FakeTransport([]);
        foreach (['/api/workspace/connections/github', '/api/workspace/repositories'] as $path) {
            $response = $this->send('GET', $path, transport: $transport);
            self::assertSame([503, 'github_token_unreadable'], [$response->getStatusCode(), $this->data($response)['error']['code']]);
        }
        self::assertSame(['configured' => true, 'status' => 'connected', 'credentialVersion' => 1], $this->data($this->send('GET', '/api/auth/session', transport: $transport))['github']);
        self::assertSame([], $transport->paths);
    }

    private function save(string $token, int $expected, \Glimpse\Source\Transport $transport): ConnectionStatus
    {
        return (new Services($this->settings, github: new FixedTransports($transport)))->github->save($this->user, $token, $expected, 'fixture');
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

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        return array_values($read->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, ?\Glimpse\Source\Transport $transport = null, ?string $csrf = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([Cookies::WORKSPACE => $this->session]);
        if ($body !== null) {
            $request = $request->withHeader('Origin', $this->settings->origin)->withHeader('Content-Type', 'application/json')
                ->withHeader('X-CSRF-Token', $csrf ?? $this->user->csrfToken);
            $request->getBody()->write(json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR));
            $request->getBody()->rewind();
        }
        return Application::create($this->settings, new FixedTransports($transport ?? new FakeTransport([])))->handle($request);
    }
}
