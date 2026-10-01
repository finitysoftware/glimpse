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
use Glimpse\Tests\Fixtures\FixedGitLabTransports;
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\GitLabRepositoryTransport;
use Glimpse\Tests\Fixtures\RepositoryTransport;
use Glimpse\Tests\Fixtures\Workspace;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/** GitHub tokens and share URLs in the admin panel: shown on request, never audited. */
final class AdminSecretsTest extends TestCase
{
    private const PASSWORD = 'admin panel test password';
    private Settings $settings;
    private PDO $pdo;
    private RepositoryTransport $transport;
    private FixedTransports $transports;
    private GitLabRepositoryTransport $gitlab;
    private FixedGitLabTransports $gitlabTransports;
    private string $admin;

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
            workspaceOrigins: ['https://glimpse.example', 'https://share.example'],
            sharePublicOrigin: 'https://share.example',
        );
        $this->pdo = Workspace::migrate($this->settings);
        Workspace::reset($this->pdo);
        $this->transport = new RepositoryTransport();
        $this->transports = new FixedTransports($this->transport);
        $this->gitlab = new GitLabRepositoryTransport();
        $this->gitlabTransports = new FixedGitLabTransports($this->gitlab);
        (new Services($this->settings))->accounts->create('root.admin', self::PASSWORD, true);
        $login = $this->send('POST', '/api/admin/auth/login', ['username' => 'root.admin', 'password' => self::PASSWORD], admin: false);
        self::assertSame(1, preg_match('/^glimpse_platform_admin=([a-f0-9]{64});/', $login->getHeaderLine('Set-Cookie'), $match));
        $this->admin = $match[1] ?? '';
    }

    public function testAdministratorShowsReplacesAndRemovesAUsersToken(): void
    {
        $session = Workspace::signIn($this->settings, 'dana', github: $this->transports);
        $dana = $this->userId('dana');
        $plain = $this->userId('root.admin');
        $audit = (int) $this->scalar('SELECT COUNT(*) FROM audit_events');
        self::assertSame(['token' => Workspace::token('dana')], $this->data($this->send('POST', '/api/admin/users/' . $dana . '/github/token/reveal', [])));
        self::assertSame(['token' => null], $this->data($this->send('POST', '/api/admin/users/' . $plain . '/github/token/reveal', [])));
        self::assertSame(404, $this->send('POST', '/api/admin/users/999999/github/token/reveal', [])->getStatusCode());
        self::assertSame(401, $this->send('POST', '/api/admin/users/' . $dana . '/github/token/reveal', [], admin: false, workspace: $session)->getStatusCode());
        // The owner's Connections still shows only the mask.
        $connection = $this->send('GET', '/api/workspace/connections/github', admin: false, workspace: $session);
        self::assertStringNotContainsString(Workspace::token('dana'), (string) $connection->getBody());

        $replaced = $this->send('PUT', '/api/admin/users/' . $dana . '/github/token', ['token' => ' fixture_token_from_admin ', 'expectedVersion' => 1]);
        self::assertSame(200, $replaced->getStatusCode(), (string) $replaced->getBody());
        self::assertSame(['connected', 2], [$this->data($replaced)['connection']['status'], $this->data($replaced)['connection']['credentialVersion']]);
        self::assertStringNotContainsString('fixture_token_from_admin', (string) $replaced->getBody());
        self::assertSame('fixture_token_from_admin', $this->data($this->send('POST', '/api/admin/users/' . $dana . '/github/token/reveal', []))['token']);
        $stale = $this->send('PUT', '/api/admin/users/' . $dana . '/github/token', ['token' => 'fixture_token_stale', 'expectedVersion' => 1]);
        self::assertSame([409, 'connection_changed'], [$stale->getStatusCode(), $this->data($stale)['error']['code']]);
        $this->transport->status = 401;
        $rejected = $this->send('PUT', '/api/admin/users/' . $dana . '/github/token', ['token' => 'fixture_token_rejected', 'expectedVersion' => 2]);
        self::assertSame([422, 'github_token_rejected'], [$rejected->getStatusCode(), $this->data($rejected)['error']['code']]);
        $this->transport->status = 200;
        self::assertSame('fixture_token_from_admin', $this->data($this->send('POST', '/api/admin/users/' . $dana . '/github/token/reveal', []))['token']);

        $removed = $this->data($this->send('POST', '/api/admin/users/' . $dana . '/github/token/remove', []));
        self::assertSame(['not_configured', false], [$removed['connection']['status'], $removed['connection']['configured']]);
        self::assertSame(['token' => null], $this->data($this->send('POST', '/api/admin/users/' . $dana . '/github/token/reveal', [])));
        self::assertSame('github_not_connected', $this->data($this->send('GET', '/api/workspace/repositories', admin: false, workspace: $session))['error']['code']);
        self::assertSame($audit, (int) $this->scalar('SELECT COUNT(*) FROM audit_events'));
    }

    public function testAdministratorListsShowsAndRevokesLinksOfEveryOwner(): void
    {
        $dana = Workspace::signIn($this->settings, 'dana', github: $this->transports);
        $ema = Workspace::signIn($this->settings, 'ema', github: $this->transports);
        $first = $this->link($dana, 'alpha', 'Client review');
        $second = $this->link($ema, 'beta', 'Design notes');
        $audit = (int) $this->scalar('SELECT COUNT(*) FROM audit_events');

        $all = $this->send('GET', '/api/admin/shares');
        self::assertSame(200, $all->getStatusCode(), (string) $all->getBody());
        $items = $this->data($all)['items'];
        self::assertSame([$second['publicId'], $first['publicId']], array_column($items, 'publicId'));
        self::assertSame(
            ['ema', 'fixture', 'beta', 'active', [['branch' => 'main', 'mode' => 'all', 'selectedCount' => 0, 'profileVersion' => null]]],
            [$items[0]['owner']['username'], $items[0]['repository']['owner'], $items[0]['repository']['name'], $items[0]['status'], $items[0]['policies']],
        );
        foreach ([$first['secret'], $second['secret'], '/s/'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $all->getBody());
        }
        self::assertSame([$first['publicId']], array_column($this->data($this->send('GET', '/api/admin/shares?user=' . $this->userId('dana')))['items'], 'publicId'));
        self::assertSame([$second['publicId']], array_column($this->data($this->send('GET', '/api/admin/shares?q=fixture%2Fbeta'))['items'], 'publicId'));
        self::assertSame([$first['publicId']], array_column($this->data($this->send('GET', '/api/admin/shares?q=client'))['items'], 'publicId'));
        self::assertSame(400, $this->send('GET', '/api/admin/shares?status=lost')->getStatusCode());

        $url = $this->data($this->send('POST', '/api/admin/shares/' . $first['publicId'] . '/reveal', []))['url'];
        self::assertSame('https://share.example/s/' . $first['publicId'] . '#' . $first['secret'], $url);
        self::assertSame($audit, (int) $this->scalar('SELECT COUNT(*) FROM audit_events'));

        $visitor = $this->visitor($first);
        self::assertSame(200, $this->send('POST', '/api/admin/shares/' . $first['publicId'] . '/revoke', [])->getStatusCode());
        self::assertSame('revoked', $this->data($this->send('GET', '/api/admin/shares?status=revoked'))['items'][0]['status']);
        $gone = $this->send('GET', '/api/shares/' . $first['publicId'] . '/browse', admin: false, visitor: $visitor);
        self::assertSame([404, 'share_unavailable'], [$gone->getStatusCode(), $this->data($gone)['error']['code']]);
        self::assertSame(404, $this->send('POST', '/api/admin/shares/' . $first['publicId'] . '/reveal', [])->getStatusCode());
        self::assertSame(404, $this->send('POST', '/api/admin/shares/' . str_repeat('0', 32) . '/revoke', [])->getStatusCode());
        self::assertSame(404, $this->send('POST', '/api/admin/shares/not-a-link/reveal', [])->getStatusCode());
        // The other owner's link is untouched.
        self::assertSame('active', $this->data($this->send('GET', '/api/admin/shares?user=' . $this->userId('ema')))['items'][0]['status']);
        self::assertSame($audit, (int) $this->scalar('SELECT COUNT(*) FROM audit_events'));
    }

    public function testAdministratorManagesAUsersGitLabConnection(): void
    {
        $session = Workspace::signIn($this->settings, 'dana', github: $this->transports, gitlab: $this->gitlabTransports);
        $dana = $this->userId('dana');
        $plain = $this->userId('root.admin');
        $audit = (int) $this->scalar('SELECT COUNT(*) FROM audit_events');
        self::assertSame(['token' => Workspace::gitlabToken('dana'), 'baseUrl' => Workspace::GITLAB_URL], $this->data($this->send('POST', '/api/admin/users/' . $dana . '/gitlab/token/reveal', [])));
        self::assertSame(['token' => null, 'baseUrl' => null], $this->data($this->send('POST', '/api/admin/users/' . $plain . '/gitlab/token/reveal', [])));
        self::assertSame(404, $this->send('POST', '/api/admin/users/999999/gitlab/token/reveal', [])->getStatusCode());

        $list = $this->data($this->send('GET', '/api/admin/users?sort=username'))['items'];
        self::assertSame(
            [['username' => 'dana', 'gitlab' => ['status' => 'connected', 'username' => 'fixture-gitlab', 'baseUrl' => Workspace::GITLAB_URL]],
                ['username' => 'root.admin', 'gitlab' => ['status' => 'not_configured', 'username' => null, 'baseUrl' => null]]],
            array_map(static fn(array $user): array => ['username' => $user['username'], 'gitlab' => $user['gitlab']], $list),
        );
        self::assertSame(['dana'], array_column($this->data($this->send('GET', '/api/admin/users?gitlab=connected'))['items'], 'username'));
        self::assertSame(['root.admin'], array_column($this->data($this->send('GET', '/api/admin/users?gitlab=not_configured'))['items'], 'username'));
        self::assertSame(400, $this->send('GET', '/api/admin/users?gitlab=lost')->getStatusCode());
        $detail = $this->data($this->send('GET', '/api/admin/users/' . $dana))['gitlabConnection'];
        self::assertSame(
            [true, 'connected', 'fixture-gitlab', Workspace::GITLAB_URL, '2027-03-01', 1],
            [$detail['configured'], $detail['status'], $detail['username'], $detail['baseUrl'], $detail['tokenExpiresAt'], $detail['credentialVersion']],
        );

        $replaced = $this->send('PUT', '/api/admin/users/' . $dana . '/gitlab', ['baseUrl' => 'https://code.example.org', 'token' => ' fixture_gitlab_admin ', 'expectedVersion' => 1]);
        self::assertSame(200, $replaced->getStatusCode(), (string) $replaced->getBody());
        self::assertSame(['https://code.example.org', 2], [$this->data($replaced)['gitlabConnection']['baseUrl'], $this->data($replaced)['gitlabConnection']['credentialVersion']]);
        self::assertStringNotContainsString('fixture_gitlab_admin', (string) $replaced->getBody());
        self::assertSame(['token' => 'fixture_gitlab_admin', 'baseUrl' => 'https://code.example.org'], $this->data($this->send('POST', '/api/admin/users/' . $dana . '/gitlab/token/reveal', [])));
        $stale = $this->send('PUT', '/api/admin/users/' . $dana . '/gitlab', ['baseUrl' => Workspace::GITLAB_URL, 'token' => 'fixture_gitlab_stale', 'expectedVersion' => 1]);
        self::assertSame([409, 'connection_changed'], [$stale->getStatusCode(), $this->data($stale)['error']['code']]);
        $this->gitlab->status = 401;
        $rejected = $this->send('PUT', '/api/admin/users/' . $dana . '/gitlab', ['baseUrl' => Workspace::GITLAB_URL, 'token' => 'fixture_gitlab_rejected', 'expectedVersion' => 2]);
        self::assertSame([422, 'gitlab_token_rejected'], [$rejected->getStatusCode(), $this->data($rejected)['error']['code']]);
        $this->gitlab->status = 200;
        self::assertSame(400, $this->send('PUT', '/api/admin/users/' . $dana . '/gitlab', ['token' => 'fixture_gitlab_admin', 'expectedVersion' => 2])->getStatusCode());

        $removed = $this->data($this->send('POST', '/api/admin/users/' . $dana . '/gitlab/remove', []));
        self::assertSame([false, 'not_configured'], [$removed['gitlabConnection']['configured'], $removed['gitlabConnection']['status']]);
        self::assertTrue($removed['connection']['configured']);
        self::assertSame(
            ['configured' => false, 'status' => 'not_configured', 'credentialVersion' => 0],
            $this->data($this->send('GET', '/api/auth/session', admin: false, workspace: $session))['gitlab'],
        );

        // A new account can get both connections at once; a rejected GitLab token creates nothing.
        $body = ['firstName' => 'Ema', 'lastName' => 'Lab', 'username' => 'ema', 'password' => 'ema account password', 'role' => 'user'];
        self::assertSame(400, $this->send('POST', '/api/admin/users', $body + ['gitlabToken' => 'fixture_gitlab_new'])->getStatusCode());
        $created = $this->send('POST', '/api/admin/users', $body + ['githubToken' => 'fixture_github_new', 'gitlabUrl' => Workspace::GITLAB_URL, 'gitlabToken' => 'fixture_gitlab_new']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame(['connected', 'connected'], [$this->data($created)['connection']['status'], $this->data($created)['gitlabConnection']['status']]);
        $users = (int) $this->scalar('SELECT COUNT(*) FROM users');
        $this->gitlab->status = 401;
        $refused = $this->send('POST', '/api/admin/users', ['username' => 'ivo'] + $body + ['gitlabUrl' => Workspace::GITLAB_URL, 'gitlabToken' => 'fixture_gitlab_bad']);
        self::assertSame([422, 'gitlab_token_rejected'], [$refused->getStatusCode(), $this->data($refused)['error']['code']]);
        self::assertSame($users, (int) $this->scalar('SELECT COUNT(*) FROM users'));
        $this->gitlab->status = 200;

        $dashboard = $this->data($this->send('GET', '/api/admin/dashboard'));
        self::assertSame([1, 0, 2], [$dashboard['gitlab']['connected'], $dashboard['gitlab']['expiringSoon'], $dashboard['gitlab']['notConfigured']]);
        self::assertSame(2, $dashboard['users']['withoutGitLab']);
        self::assertSame($audit, (int) $this->scalar('SELECT COUNT(*) FROM audit_events'));

        // Access links show the platform and instance of each repository.
        $link = $this->send('POST', '/api/workspace/gitlab/projects/43/shares', ['name' => 'GitLab notes', 'branches' => ['main'],
            'visibility' => [['branch' => 'main', 'allFiles' => true]]], admin: false, workspace: Workspace::signIn($this->settings, 'mia', gitlab: $this->gitlabTransports));
        self::assertSame(201, $link->getStatusCode(), (string) $link->getBody());
        $shares = $this->data($this->send('GET', '/api/admin/shares?q=group%2Fbeta'))['items'];
        self::assertSame(['provider' => 'gitlab', 'instance' => Workspace::GITLAB_URL, 'owner' => 'group', 'name' => 'beta', 'active' => true], $shares[0]['repository']);
    }

    /** @return array{publicId: string, secret: string} */
    private function link(string $session, string $repository, string $name): array
    {
        $response = $this->send('POST', '/api/workspace/repositories/fixture/' . $repository . '/shares', ['name' => $name, 'branches' => ['main'],
            'visibility' => [['branch' => 'main', 'allFiles' => true]]], admin: false, workspace: $session);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        return ['publicId' => (string) $data['publicId'], 'secret' => explode('#', (string) $data['url'])[1]];
    }

    /** @param array{publicId: string, secret: string} $link */
    private function visitor(array $link): string
    {
        $response = $this->send('POST', '/api/shares/' . $link['publicId'] . '/exchange', ['secret' => $link['secret']], admin: false);
        self::assertSame(1, preg_match('/glimpse_visitor=([a-f0-9]{64})/', $response->getHeaderLine('Set-Cookie'), $match));
        return $match[1] ?? '';
    }

    private function userId(string $username): int
    {
        return (int) $this->scalar("SELECT id FROM users WHERE username = '" . $username . "'");
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, bool $admin = true, ?string $workspace = null, ?string $visitor = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);
        $cookies = [];
        if ($admin) {
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
        return Application::create($this->settings, $this->transports, $this->gitlabTransports)->handle($request);
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
