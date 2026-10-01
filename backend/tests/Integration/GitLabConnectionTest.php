<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Application;
use Glimpse\Auth\UserContext;
use Glimpse\Cache\PreviewCache;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Database\Connection;
use Glimpse\GitHub\GitHubCachePolicy;
use Glimpse\GitLab\GitLabCachePolicy;
use Glimpse\Http\Cookies;
use Glimpse\Services;
use Glimpse\Settings;
use Glimpse\Source\HttpResult;
use Glimpse\Source\Provider;
use Glimpse\Source\SourceAccess;
use Glimpse\Source\TokenCipher;
use Glimpse\Source\Transport;
use Glimpse\Tests\Fixtures\FakeTransport;
use Glimpse\Tests\Fixtures\FixedGitLabTransports;
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\GitLabRepositoryTransport;
use Glimpse\Tests\Fixtures\RepositoryTransport;
use Glimpse\Tests\Fixtures\Workspace;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/** GitLab connection in the workspace and Disconnect for both platforms. */
final class GitLabConnectionTest extends TestCase
{
    private const TOKEN = 'fixture_gitlab_token_0123456789';
    private Settings $settings;
    private PDO $pdo;
    private UserContext $user;
    private string $session;
    private FixedGitLabTransports $gitlab;

    protected function setUp(): void
    {
        $dsn = getenv('GLIMPSE_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('A dedicated MySQL/MariaDB test database is required.');
        }
        if (getenv('GLIMPSE_TEST_DB_RESET') !== '1' || !preg_match('/dbname=glimpse_test(?:;|$)/', $dsn)) {
            self::fail('Refusing to reset a non-test database.');
        }
        $this->settings = $this->settings();
        $this->pdo = (new Connection($this->settings))->get();
        Workspace::migrate($this->settings);
        Workspace::reset($this->pdo);
        $this->session = Workspace::signIn($this->settings);
        $this->user = (new Services($this->settings))->auth->authenticate($this->session);
    }

    public function testMissingConnectionIsReportedWithoutGitLabCalls(): void
    {
        $upstream = new FakeTransport([]);
        self::assertSame(
            ['configured' => false, 'status' => 'not_configured', 'baseUrl' => null, 'defaultUrl' => 'https://gitlab.com', 'maskedToken' => null,
                'credentialVersion' => 0, 'gitlabUsername' => null, 'tokenExpiresAt' => null, 'scopes' => null, 'lastCheckedAt' => null, 'errorCode' => null],
            $this->data($this->send('GET', '/api/workspace/connections/gitlab', gitlab: $upstream)),
        );
        self::assertSame(['configured' => false, 'status' => 'not_configured', 'credentialVersion' => 0], $this->data($this->send('GET', '/api/auth/session'))['gitlab']);
        $probe = $this->send('POST', '/api/workspace/connections/gitlab/probe', [], gitlab: $upstream);
        self::assertSame([409, 'gitlab_not_connected'], [$probe->getStatusCode(), $this->data($probe)['error']['code']]);
        self::assertFalse($this->data($this->send('POST', '/api/workspace/connections/gitlab/disconnect', [], gitlab: $upstream))['configured']);
        self::assertSame([], $upstream->paths);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE action LIKE 'gitlab%'"));
    }

    public function testSaveVerifiesTheInstanceAndEncryptsTheTokenBoundToIt(): void
    {
        $upstream = new GitLabRepositoryTransport();
        $response = $this->send('PUT', '/api/workspace/connections/gitlab', ['baseUrl' => ' HTTPS://GitLab.Example/api/v4 ', 'token' => ' ' . self::TOKEN . ' ', 'expectedVersion' => 0], gitlab: $upstream);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertSame(['configured' => true, 'status' => 'connected', 'baseUrl' => 'https://gitlab.example', 'credentialVersion' => 1, 'gitlabUsername' => 'fixture-gitlab',
            'tokenExpiresAt' => '2027-03-01', 'scopes' => ['read_api']], array_intersect_key($data, array_flip(['configured', 'status', 'baseUrl', 'credentialVersion', 'gitlabUsername', 'tokenExpiresAt', 'scopes'])));
        self::assertSame(substr(self::TOKEN, 0, 15) . str_repeat('*', strlen(self::TOKEN) - 15), $data['maskedToken']);
        self::assertStringNotContainsString(self::TOKEN, (string) $response->getBody());
        self::assertSame([['url' => 'https://gitlab.example', 'token' => self::TOKEN]], $this->gitlab->connections);
        self::assertSame(['/user', '/personal_access_tokens/self', '/projects?membership=true&min_access_level=20&order_by=path&sort=asc&per_page=30&page=1'], $upstream->paths);
        $row = $this->row('SELECT id, base_url, encrypted_token, encryption_key_id FROM gitlab_connections');
        self::assertStringNotContainsString(substr(self::TOKEN, 0, 12), (string) $row['encrypted_token']);
        $cipher = new TokenCipher(Workspace::tokenKey(), Workspace::TOKEN_KEY_ID, Provider::GitLab);
        self::assertSame(self::TOKEN, $cipher->decrypt($this->user->userId, (int) $row['id'], (string) $row['encryption_key_id'], (string) $row['encrypted_token'], 'https://gitlab.example'));
        self::assertSame(['gitlab_token_configured'], $this->column("SELECT action FROM audit_events WHERE action LIKE 'gitlab%'"));
        self::assertSame(['configured' => true, 'status' => 'connected', 'credentialVersion' => 1], $this->data($this->send('GET', '/api/auth/session'))['gitlab']);
        self::assertSame(['configured' => false, 'status' => 'not_configured', 'credentialVersion' => 0], $this->data($this->send('GET', '/api/auth/session'))['github']);

        // A token moved to another instance in the database cannot be decrypted, so it is never sent there.
        $this->pdo->exec("UPDATE gitlab_connections SET base_url = 'https://attacker.example'");
        $moved = $this->send('GET', '/api/workspace/connections/gitlab');
        self::assertSame([503, 'gitlab_token_unreadable'], [$moved->getStatusCode(), $this->data($moved)['error']['code']]);
        self::assertSame([], $this->gitlab->connections);
    }

    public function testRefusedCandidatesKeepTheSavedConnection(): void
    {
        $this->send('PUT', '/api/workspace/connections/gitlab', ['baseUrl' => Workspace::GITLAB_URL, 'token' => self::TOKEN, 'expectedVersion' => 0], gitlab: new GitLabRepositoryTransport());
        $saved = $this->row('SELECT encrypted_token, credential_version FROM gitlab_connections');
        $scoped = new GitLabRepositoryTransport();
        $scoped->scopes = ['read_user', 'read_repository'];
        foreach ([
            [['baseUrl' => Workspace::GITLAB_URL, 'token' => 'fixture_other_token', 'expectedVersion' => 1], $scoped, 422, 'gitlab_token_scope'],
            [['baseUrl' => Workspace::GITLAB_URL, 'token' => 'fixture_other_token', 'expectedVersion' => 1], new FakeTransport([new HttpResult(401, [], '{}')]), 422, 'gitlab_token_rejected'],
            [['baseUrl' => Workspace::GITLAB_URL, 'token' => 'fixture_other_token', 'expectedVersion' => 1], new FakeTransport([new HttpResult(403, [], '{}')]), 422, 'gitlab_token_restricted'],
            [['baseUrl' => 'http://gitlab.example', 'token' => 'fixture_other_token', 'expectedVersion' => 1], new FakeTransport([]), 400, 'invalid_gitlab_url'],
            [['baseUrl' => 'https://gitlab.example:8443', 'token' => 'fixture_other_token', 'expectedVersion' => 1], new FakeTransport([]), 422, 'gitlab_url_not_allowed'],
            [['baseUrl' => Workspace::GITLAB_URL, 'token' => 'fixture_other_token', 'expectedVersion' => 0], new FakeTransport([]), 409, 'connection_changed'],
            [['baseUrl' => Workspace::GITLAB_URL, 'expectedVersion' => 1], new FakeTransport([]), 400, 'invalid_input'],
            [['baseUrl' => Workspace::GITLAB_URL, 'token' => 'with space', 'expectedVersion' => 1], new FakeTransport([]), 422, 'gitlab_token_invalid_format'],
        ] as [$body, $upstream, $status, $code]) {
            $response = $this->send('PUT', '/api/workspace/connections/gitlab', $body, gitlab: $upstream);
            self::assertSame([$status, $code], [$response->getStatusCode(), $this->data($response)['error']['code'] ?? null], (string) json_encode($body));
        }
        self::assertSame($saved, $this->row('SELECT encrypted_token, credential_version FROM gitlab_connections'));
        // An instance that does not report token details is accepted without scope and expiry.
        $older = new GitLabRepositoryTransport();
        $older->scopes = null;
        $data = $this->data($this->send('PUT', '/api/workspace/connections/gitlab', ['baseUrl' => Workspace::GITLAB_URL, 'token' => 'fixture_other_token', 'expectedVersion' => 1], gitlab: $older));
        self::assertSame([2, null, null], [$data['credentialVersion'], $data['scopes'], $data['tokenExpiresAt']]);
    }

    public function testAnotherInstanceStartsANewGitLabGenerationWithoutTouchingGitHub(): void
    {
        $this->send('PUT', '/api/workspace/connections/github', ['token' => 'fixture_github_token', 'expectedVersion' => 0], github: new RepositoryTransport());
        $this->send('PUT', '/api/workspace/connections/gitlab', ['baseUrl' => Workspace::GITLAB_URL, 'token' => self::TOKEN, 'expectedVersion' => 0], gitlab: new GitLabRepositoryTransport());
        $github = $this->cacheOn(new Connection($this->settings), new SourceAccess(Provider::GitHub, $this->user->userId, 1, new FakeTransport([]), Provider::GITHUB_INSTANCE, new GitHubCachePolicy()));
        $github->put($github->key('kept', 1), PreviewCache::scope(Provider::GitHub, 'fixture/alpha'), null, new HttpResult(200, [], '{}'), 3600);
        $gitlab = $this->cacheOn(new Connection($this->settings), new SourceAccess(Provider::GitLab, $this->user->userId, 1, new FakeTransport([]), Workspace::GITLAB_URL, new GitLabCachePolicy()));
        $gitlab->put($gitlab->key('replaced', 1), PreviewCache::scope(Provider::GitLab, '42'), null, new HttpResult(200, [], '{}'), 3600);
        $data = $this->data($this->send('PUT', '/api/workspace/connections/gitlab', ['baseUrl' => 'https://code.example.org/gitlab', 'token' => self::TOKEN, 'expectedVersion' => 1], gitlab: new GitLabRepositoryTransport()));
        self::assertSame(['https://code.example.org/gitlab', 2], [$data['baseUrl'], $data['credentialVersion']]);
        self::assertNotNull($github->get($github->key('kept', 1)));
        self::assertNull($gitlab->get($gitlab->key('replaced', 1)));
        self::assertSame('{"credentialVersion":2,"instanceChanged":true}', $this->scalar("SELECT metadata FROM audit_events WHERE action = 'gitlab_token_replaced'"));
    }

    public function testProbeAndDisconnectOfBothPlatforms(): void
    {
        $this->send('PUT', '/api/workspace/connections/github', ['token' => 'fixture_github_token', 'expectedVersion' => 0], github: new RepositoryTransport());
        $this->send('PUT', '/api/workspace/connections/gitlab', ['baseUrl' => Workspace::GITLAB_URL, 'token' => self::TOKEN, 'expectedVersion' => 0], gitlab: new GitLabRepositoryTransport());
        $upstream = new GitLabRepositoryTransport();
        $upstream->expiresAt = '2026-10-01';
        $checked = $this->data($this->send('POST', '/api/workspace/connections/gitlab/probe', [], gitlab: $upstream));
        self::assertSame(['connected', '2026-10-01'], [$checked['status'], $checked['tokenExpiresAt']]);
        $gitlab = $this->cacheOn(new Connection($this->settings), new SourceAccess(Provider::GitLab, $this->user->userId, 1, new FakeTransport([]), Workspace::GITLAB_URL, new GitLabCachePolicy()));
        $gitlab->put($gitlab->key('entry', 1), PreviewCache::scope(Provider::GitLab, '42'), null, new HttpResult(200, [], '{}'), 3600);
        $rejected = $this->data($this->send('POST', '/api/workspace/connections/gitlab/probe', [], gitlab: new FakeTransport([new HttpResult(401, [], '{}')])));
        self::assertSame(['credentials_rejected', 'gitlab_credentials_rejected'], [$rejected['status'], $rejected['errorCode']]);
        self::assertNull($gitlab->get($gitlab->key('entry', 1)));
        $cache = $this->data($this->send('GET', '/api/workspace/preview-cache'));
        self::assertSame(['github', 'gitlab'], array_keys($cache['providers']));

        $gone = $this->data($this->send('POST', '/api/workspace/connections/gitlab/disconnect', []));
        self::assertSame([false, 'not_configured', null], [$gone['configured'], $gone['status'], $gone['maskedToken']]);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM gitlab_connections'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM github_connections'));
        $session = $this->data($this->send('GET', '/api/auth/session'));
        self::assertSame([false, true], [$session['gitlab']['configured'], $session['github']['configured']]);
        $github = $this->data($this->send('POST', '/api/workspace/connections/github/disconnect', []));
        self::assertSame([false, 'not_configured'], [$github['configured'], $github['status']]);
        self::assertSame(['github_token_removed', 'gitlab_token_removed'], $this->column("SELECT action FROM audit_events WHERE action LIKE '%_token_removed' ORDER BY action"));
        $repositories = $this->send('GET', '/api/workspace/repositories');
        self::assertSame([409, 'github_not_connected'], [$repositories->getStatusCode(), $this->data($repositories)['error']['code']]);
        // A new connection continues the credential generation, so no old cache generation is reused.
        $again = $this->data($this->send('PUT', '/api/workspace/connections/gitlab', ['baseUrl' => Workspace::GITLAB_URL, 'token' => self::TOKEN, 'expectedVersion' => 0], gitlab: new GitLabRepositoryTransport()));
        self::assertSame(2, $again['credentialVersion']);
        // Disconnect needs the workspace CSRF token like every other change.
        self::assertSame(403, $this->send('POST', '/api/workspace/connections/gitlab/disconnect', [], csrf: 'wrong')->getStatusCode());
    }

    public function testConfigurationValidatesGitLabHosts(): void
    {
        self::assertSame(['https://gitlab.com', [], false], [$this->settings->gitlabDefaultUrl, $this->settings->gitlabAllowedHosts, $this->settings->gitlabAllowPrivateAddresses]);
        foreach ([['gitlabAllowPrivateAddresses' => true, 'environment' => 'production', 'origin' => 'https://glimpse.example'], ['gitlabAllowedHosts' => ['gitlab.com/path']]] as $overrides) {
            try {
                $this->settings($overrides);
                self::fail('Invalid GitLab configuration accepted: ' . json_encode($overrides));
            } catch (\RuntimeException $error) {
                self::assertSame('Invalid server configuration.', $error->getMessage());
            }
        }
        // The default address is a GitLab URL, so the GitLab connection checks it when the API starts.
        foreach ([['gitlabDefaultUrl' => 'http://gitlab.com'], ['gitlabDefaultUrl' => 'https://code.example.org', 'gitlabAllowedHosts' => ['gitlab.com']]] as $overrides) {
            try {
                Application::create($this->settings($overrides), new FixedTransports(new FakeTransport([])), new FixedGitLabTransports(new FakeTransport([])));
                self::fail('Invalid GitLab configuration accepted: ' . json_encode($overrides));
            } catch (\RuntimeException $error) {
                self::assertSame('Invalid server configuration.', $error->getMessage());
            }
        }
        self::assertSame(['gitlab.com', 'code.example.org:8443'], $this->settings(['gitlabDefaultUrl' => 'https://code.example.org:8443', 'gitlabAllowedHosts' => ['gitlab.com', 'code.example.org:8443']])->gitlabAllowedHosts);
        self::assertTrue($this->settings(['gitlabAllowPrivateAddresses' => true])->gitlabAllowPrivateAddresses);
    }

    /** @param array<string, mixed> $overrides */
    private function settings(array $overrides = []): Settings
    {
        $dsn = (string) getenv('GLIMPSE_TEST_DB_DSN');
        return new Settings(...$overrides + ['environment' => 'test', 'origin' => 'https://glimpse.example', 'database' => ['dsn' => $dsn,
            'username' => getenv('GLIMPSE_TEST_DB_USER') ?: 'root', 'password' => getenv('GLIMPSE_TEST_DB_PASSWORD') ?: ''],
            'githubTokenKey' => Workspace::tokenKey(), 'githubTokenKeyId' => Workspace::TOKEN_KEY_ID]);
    }

    private function scalar(string $sql): mixed
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        return $read->fetchColumn();
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
    private function send(string $method, string $path, ?array $body = null, ?Transport $github = null, ?Transport $gitlab = null, ?string $csrf = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([Cookies::WORKSPACE => $this->session]);
        if ($body !== null) {
            $request = $request->withHeader('Origin', $this->settings->origin)->withHeader('Content-Type', 'application/json')
                ->withHeader('X-CSRF-Token', $csrf ?? $this->user->csrfToken);
            $request->getBody()->write(json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR));
            $request->getBody()->rewind();
        }
        $this->gitlab = new FixedGitLabTransports($gitlab ?? new FakeTransport([]));
        return Application::create($this->settings, new FixedTransports($github ?? new FakeTransport([])), $this->gitlab)->handle($request);
    }

    /** A preview cache on its own database connection, like a separate request. */
    private function cacheOn(Connection $connection, SourceAccess $access): PreviewCache
    {
        return new PreviewCache($connection, $access, new PreviewCaches($connection));
    }
}
