<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Application;
use Glimpse\Auth\Csrf;
use Glimpse\Auth\UserAuth;
use Glimpse\Database\Connection;
use Glimpse\Http\Cookies;
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

/** GitLab repositories in the workspace next to GitHub ones: catalogue, browsing, visibility and links. */
final class GitLabRepositoriesTest extends TestCase
{
    private const SHA = GitLabRepositoryTransport::SHA;
    private const PROJECT = '/api/workspace/gitlab/projects/42';
    private Settings $settings;
    private PDO $pdo;
    private string $owner;
    private GitLabRepositoryTransport $gitlab;
    private RepositoryTransport $github;

    protected function setUp(): void
    {
        $dsn = getenv('GLIMPSE_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('A dedicated MySQL/MariaDB test database is required.');
        }
        if (getenv('GLIMPSE_TEST_DB_RESET') !== '1' || !preg_match('/dbname=glimpse_test(?:;|$)/', $dsn)) {
            self::fail('Refusing to reset a non-test database.');
        }
        $this->settings = new Settings(environment: 'test', origin: 'https://glimpse.example', database: [
            'dsn' => $dsn, 'username' => getenv('GLIMPSE_TEST_DB_USER') ?: 'root', 'password' => getenv('GLIMPSE_TEST_DB_PASSWORD') ?: '',
        ], shareEncryptionKey: base64_encode(str_repeat('k', 32)), githubTokenKey: Workspace::tokenKey(), githubTokenKeyId: Workspace::TOKEN_KEY_ID);
        $this->pdo = (new Connection($this->settings))->get();
        Workspace::migrate($this->settings);
        Workspace::reset($this->pdo);
        $this->github = new RepositoryTransport();
        $this->gitlab = new GitLabRepositoryTransport();
        $this->owner = Workspace::signIn($this->settings, github: new FixedTransports($this->github), gitlab: new FixedGitLabTransports($this->gitlab));
    }

    public function testCatalogueIsLoadedPerPlatformAndTagsEveryRepository(): void
    {
        $gitlab = $this->data($this->send('GET', '/api/workspace/repositories?provider=gitlab'));
        self::assertSame(2, $gitlab['nextPage']);
        self::assertSame(
            [['gitlab', 42, 'group/sub/alpha', 'private'], ['gitlab', 43, 'group/beta', 'internal']],
            array_map(static fn(array $item): array => [$item['provider'], $item['id'], $item['fullName'], $item['visibility']], $gitlab['items']),
        );
        $github = $this->data($this->send('GET', '/api/workspace/repositories?provider=github'));
        self::assertSame(['github', 'fixture/alpha', 'private'], [$github['items'][0]['provider'], $github['items'][0]['fullName'], $github['items'][0]['visibility']]);
        self::assertSame($github, $this->data($this->send('GET', '/api/workspace/repositories')));
        $unknown = $this->send('GET', '/api/workspace/repositories?provider=bitbucket');
        self::assertSame([400, 'invalid_input'], [$unknown->getStatusCode(), $this->data($unknown)['error']['code']]);
        $this->send('POST', '/api/workspace/connections/gitlab/disconnect', []);
        $missing = $this->send('GET', '/api/workspace/repositories?provider=gitlab');
        self::assertSame([409, 'gitlab_not_connected'], [$missing->getStatusCode(), $this->data($missing)['error']['code']]);
        self::assertSame(200, $this->send('GET', '/api/workspace/repositories?provider=github')->getStatusCode());
    }

    public function testGitLabProjectIsBrowsedLikeAGitHubRepository(): void
    {
        $root = $this->data($this->send('GET', self::PROJECT . '/browse'));
        self::assertSame(['provider' => 'gitlab', 'id' => 42, 'fullName' => 'group/sub/alpha', 'owner' => 'group/sub', 'name' => 'alpha', 'private' => true,
            'visibility' => 'private', 'defaultBranch' => 'main'], $root['repository']);
        self::assertSame([self::SHA, 'gitlab'], [$root['commitSha'], $root['contentSource']]);
        self::assertSame(['docs', 'App.xaml', 'App.xaml.cs', 'docs-link', 'docs-submodule', 'README.md'], array_column($root['entries'], 'name'));
        $file = $this->data($this->send('GET', self::PROJECT . '/browse?kind=file&path=' . rawurlencode('docs/čitaj me.txt')));
        self::assertStringContainsString('čćžšđ', $file['file']['content']);
        self::assertSame('cache', $this->data($this->send('GET', self::PROJECT . '/browse?kind=file&path=' . rawurlencode('docs/čitaj me.txt')))['contentSource']);
        $branches = $this->data($this->send('GET', self::PROJECT . '/browse?kind=branches'));
        self::assertSame([['main', 'feature/preview', 'private'], 'main'], [$branches['items'], $branches['defaultBranch']]);
        $history = $this->data($this->send('GET', self::PROJECT . '/browse?kind=history&branch=main'));
        self::assertSame(['Improve application startup', 2], [$history['history']['items'][0]['message'], $history['history']['nextPage']]);
        $commit = $this->data($this->send('GET', self::PROJECT . '/browse?kind=commit&sha=' . self::SHA));
        self::assertSame(['App.xaml.cs', 'complete'], [$commit['detail']['files'][0]['path'], $commit['detail']['files'][0]['patchState']]);
        $search = $this->data($this->send('GET', self::PROJECT . '/browse?kind=search&q=app'));
        self::assertSame([['App.xaml', null], ['App.xaml.cs', null]], array_map(static fn(array $item): array => [$item['path'], $item['size']], $search['search']['items']));
        $missing = $this->send('GET', '/api/workspace/gitlab/projects/41/browse');
        self::assertSame([502, 'gitlab_not_found'], [$missing->getStatusCode(), $this->data($missing)['error']['code']]);
        self::assertSame(404, $this->send('GET', '/api/workspace/gitlab/projects/abc/browse')->getStatusCode());
        // The GitHub repository of the same user is untouched by GitLab requests.
        self::assertSame('github', $this->data($this->send('GET', '/api/workspace/repositories/fixture/alpha/browse'))['repository']['provider']);
    }

    public function testVisibilityAndLinksOfAGitLabProject(): void
    {
        $profile = $this->data($this->send('GET', self::PROJECT . '/visibility?branch=main'));
        self::assertSame([self::SHA, false], [$profile['headSha'], $profile['truncated']]);
        self::assertSame(['App.xaml', 'App.xaml.cs', 'README.md', 'docs/čitaj me.txt'], $profile['manifest']);
        $saved = $this->send('PUT', self::PROJECT . '/visibility', ['branch' => 'main', 'expectedVersion' => 0, 'sourceSha' => self::SHA,
            'mode' => 'selected', 'operations' => [['action' => 'select', 'path' => 'docs']]]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(['docs/čitaj me.txt'], $this->data($saved)['selected']);
        self::assertSame(['gitlab', Workspace::GITLAB_URL, 42, 'group/sub', 'alpha'], array_values($this->row('SELECT provider, instance_url, external_id, owner, name FROM repositories')));

        $created = $this->send('POST', self::PROJECT . '/shares', ['name' => 'GitLab review', 'expiresInDays' => 7, 'branches' => ['main'], 'allowHistory' => true,
            'visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $link = $this->data($created);
        $listing = $this->data($this->send('GET', self::PROJECT . '/shares'));
        self::assertSame(['GitLab review', 'selected', 1], [$listing['items'][0]['name'], $listing['items'][0]['policies'][0]['mode'], $listing['items'][0]['policies'][0]['selectedCount']]);
        self::assertSame([], $this->data($this->send('GET', '/api/workspace/gitlab/projects/43/shares'))['items']);
        self::assertSame([], $this->data($this->send('GET', '/api/workspace/repositories/fixture/alpha/shares'))['items']);
        $updated = $this->send('POST', '/api/workspace/shares/' . $link['publicId'] . '/permissions', ['branches' => ['main', 'feature/preview'], 'allowHistory' => false,
            'expectedPolicyVersion' => 1, 'visibility' => [['branch' => 'main', 'keep' => true], ['branch' => 'feature/preview', 'allFiles' => true]]]);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertContains('/projects/42/repository/branches/feature%2Fpreview', $this->gitlab->paths);

        // The visitor reads the project through the owner's GitLab connection and sees only the shared files.
        $visitor = $this->exchange($link['publicId'], explode('#', (string) $link['url'])[1]);
        $shared = $this->data($this->send('GET', '/api/shares/' . $link['publicId'] . '/browse', visitor: $visitor));
        self::assertSame(['docs'], array_column($shared['entries'], 'name'));
        self::assertSame(502, $this->send('GET', '/api/shares/' . $link['publicId'] . '/browse?kind=file&path=README.md', visitor: $visitor)->getStatusCode());

        // Disabling sharing works per platform; the GitHub repository with the same user is unaffected.
        self::assertFalse($this->data($this->send('POST', self::PROJECT . '/availability', ['active' => false]))['active']);
        self::assertSame(404, $this->send('GET', '/api/shares/' . $link['publicId'] . '/browse', visitor: $visitor)->getStatusCode());
        self::assertTrue($this->data($this->send('POST', self::PROJECT . '/availability', ['active' => true]))['active']);

        // Another instance: the old links stop serving and cannot be edited until that instance is connected again.
        $this->send('PUT', '/api/workspace/connections/gitlab', ['baseUrl' => 'https://code.example.org', 'token' => 'fixture_other_token', 'expectedVersion' => 1]);
        self::assertSame([], $this->data($this->send('GET', self::PROJECT . '/shares'))['items']);
        self::assertSame(404, $this->send('GET', '/api/shares/' . $link['publicId'] . '/browse', visitor: $visitor)->getStatusCode());
        $edit = $this->send('POST', '/api/workspace/shares/' . $link['publicId'] . '/permissions', ['branches' => ['main'], 'allowHistory' => false, 'expectedPolicyVersion' => 2,
            'visibility' => [['branch' => 'main', 'keep' => true]]]);
        self::assertSame([409, 'repository_instance_changed'], [$edit->getStatusCode(), $this->data($edit)['error']['code']]);
        $this->send('PUT', '/api/workspace/connections/gitlab', ['baseUrl' => Workspace::GITLAB_URL, 'token' => 'fixture_other_token', 'expectedVersion' => 2]);
        self::assertSame(200, $this->send('GET', '/api/shares/' . $link['publicId'] . '/browse', visitor: $visitor)->getStatusCode());
    }

    private function exchange(string $publicId, string $secret): string
    {
        $response = $this->send('POST', '/api/shares/' . $publicId . '/exchange', ['secret' => $secret], owner: false);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, preg_match('/glimpse_visitor=([a-f0-9]{64})/', $response->getHeaderLine('Set-Cookie'), $matches));
        return $matches[1] ?? '';
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return array_map(static fn(mixed $value): mixed => is_string($value) && ctype_digit($value) ? (int) $value : $value, $row);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, bool $owner = true, ?string $visitor = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);
        $cookies = [];
        if ($owner && $visitor === null) {
            $cookies[Cookies::WORKSPACE] = $this->owner;
        }
        if ($visitor !== null) {
            $cookies[Cookies::VISITOR] = $visitor;
        }
        $request = $request->withCookieParams($cookies);
        if ($body !== null) {
            $request = $request->withHeader('Origin', $this->settings->origin)->withHeader('Content-Type', 'application/json');
            if ($owner && $visitor === null) {
                $request = $request->withHeader('X-CSRF-Token', Csrf::token(UserAuth::AUDIENCE, $this->owner));
            }
            $request->getBody()->write(json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR));
            $request->getBody()->rewind();
        }
        return Application::create($this->settings, new FixedTransports($this->github), new FixedGitLabTransports($this->gitlab))->handle($request);
    }
}
