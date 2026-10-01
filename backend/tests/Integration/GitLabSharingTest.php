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

/** Access links to GitLab projects: filtered history over GitLab diffs, and visitors who never learn the platform. */
final class GitLabSharingTest extends TestCase
{
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

    public function testSelectedFilesLinkFiltersGitLabHistoryWithoutHiddenNamesOrMessages(): void
    {
        $diff = static fn(string $path, string $old = '', bool $new = false, bool $deleted = false): array => ['old_path' => $old === '' ? $path : $old, 'new_path' => $path,
            'new_file' => $new, 'renamed_file' => $old !== '', 'deleted_file' => $deleted, 'diff' => "@@ -1 +1 @@\n-old\n+new\n"];
        $sha = static fn(string $letter, int $index): string => str_repeat($letter, 39) . $index;
        $this->gitlab->history = [
            GitLabRepositoryTransport::SHA => ['message' => 'Rotate secret in App.xaml', 'files' => [$diff('App.xaml')]],
            $sha('b', 1) => ['message' => 'Mention App.xaml.cs secrets', 'files' => [$diff('README.md'), $diff('App.xaml.cs')]],
            $sha('c', 2) => ['message' => 'Move secret/plan.md', 'files' => [$diff('docs/čitaj me.txt', 'secret/plan.md')]],
            $sha('d', 3) => ['message' => 'Initial', 'files' => [$diff('README.md', '', true)]],
        ];
        $link = $this->link('README.md', 'docs');
        $visitor = $this->exchange($link);
        $history = $this->visit($link, $visitor, '?kind=history');
        self::assertSame([$sha('b', 1), $sha('c', 2), $sha('d', 3)], array_column($history['history']['items'], 'sha'));
        self::assertSame(['Changes to shared files'], array_values(array_unique(array_column($history['history']['items'], 'message'))));
        self::assertSame([''], array_values(array_unique(array_column($history['history']['items'], 'author'))));
        self::assertTrue($history['history']['filtered']);
        $moved = $this->visit($link, $visitor, '?kind=commit&sha=' . $sha('c', 2));
        self::assertSame(
            [['docs/čitaj me.txt', null, 'added', 'restricted']],
            array_map(static fn(array $file): array => [$file['path'], $file['previousPath'], $file['status'], $file['patchState']], $moved['detail']['files']),
        );
        $mixed = $this->visit($link, $visitor, '?kind=commit&sha=' . $sha('b', 1));
        self::assertSame([['README.md', 1, 1, 'complete']], array_map(static fn(array $file): array => [$file['path'], $file['additions'], $file['deletions'], $file['patchState']], $mixed['detail']['files']));
        self::assertSame(['additions' => 1, 'deletions' => 1, 'total' => 2], $mixed['detail']['stats']);
        foreach ([$history, $moved, $mixed] as $response) {
            foreach (['App.xaml', 'secret', 'Rotate', 'Hidden Author'] as $hidden) {
                self::assertStringNotContainsString($hidden, (string) json_encode($response));
            }
        }
        // A commit that changes only hidden files looks exactly like a missing commit, with the same neutral error on both platforms.
        $hiddenCommit = $this->visitRaw($link, $visitor, '?kind=commit&sha=' . GitLabRepositoryTransport::SHA);
        $hiddenFile = $this->visitRaw($link, $visitor, '?kind=file&path=App.xaml');
        foreach ([$hiddenCommit, $hiddenFile] as $hidden) {
            self::assertSame([502, 'upstream_not_found', 'The repository or file is unavailable.'], [$hidden->getStatusCode(), $this->data($hidden)['error']['code'], $this->data($hidden)['error']['message']]);
        }
        // The owner's own history of the project stays complete.
        $owner = $this->data($this->send('GET', self::PROJECT . '/browse?kind=history'));
        self::assertSame('Rotate secret in App.xaml', $owner['history']['items'][0]['message']);
    }

    public function testVisitorsNeverLearnTheRepositoryHost(): void
    {
        $gitlabLink = $this->link('README.md');
        $githubLink = $this->githubLink();
        $visitor = $this->exchange($gitlabLink);
        $this->exchange($githubLink, $visitor);
        $views = [$this->visitRaw($gitlabLink, $visitor, ''), $this->visitRaw($githubLink, $visitor, '')];
        foreach ($views as $view) {
            $data = $this->data($view);
            self::assertSame(['id', 'fullName', 'private', 'defaultBranch'], array_keys($data['repository']));
            self::assertContains($data['contentSource'], ['upstream', 'cache']);
            foreach (['gitlab', 'github', 'GitLab', 'GitHub'] as $platform) {
                self::assertStringNotContainsString($platform, (string) $view->getBody());
            }
        }
        foreach ([$gitlabLink, $githubLink] as $link) {
            $this->gitlab->status = 429;
            $this->github->status = 429;
            $limited = $this->visitRaw($link, $visitor, '?refresh=1');
            self::assertSame([429, 'upstream_rate_limited'], [$limited->getStatusCode(), $this->data($limited)['error']['code']]);
            self::assertStringNotContainsStringIgnoringCase('git', (string) $limited->getBody());
            $this->gitlab->status = 200;
            $this->github->status = 200;
            $this->pdo->exec('UPDATE api_cache_state SET blocked_until = NULL');
        }
        // A disconnected GitLab stops the owner's GitLab links like a missing link; GitHub links keep working.
        $this->send('POST', '/api/workspace/connections/gitlab/disconnect', []);
        self::assertSame([404, 'share_unavailable'], [$this->visitRaw($gitlabLink, $visitor, '')->getStatusCode(), $this->data($this->visitRaw($gitlabLink, $visitor, ''))['error']['code']]);
        self::assertSame(200, $this->visitRaw($githubLink, $visitor, '')->getStatusCode());
    }

    /** @return array{id: string, secret: string} */
    private function link(string ...$paths): array
    {
        $saved = $this->send('PUT', self::PROJECT . '/visibility', ['branch' => 'main', 'expectedVersion' => 0, 'sourceSha' => GitLabRepositoryTransport::SHA,
            'mode' => 'selected', 'operations' => array_map(static fn(string $path): array => ['action' => 'select', 'path' => $path], $paths)]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        return $this->created($this->send('POST', self::PROJECT . '/shares', ['name' => 'GitLab review', 'expiresInDays' => 7, 'branches' => ['main'],
            'allowHistory' => true, 'visibility' => [['branch' => 'main', 'profileVersion' => $this->data($saved)['version']]]]));
    }

    /** @return array{id: string, secret: string} */
    private function githubLink(): array
    {
        return $this->created($this->send('POST', '/api/workspace/repositories/fixture/alpha/shares', ['name' => 'GitHub review', 'expiresInDays' => 7,
            'branches' => ['main'], 'allowHistory' => true, 'visibility' => [['branch' => 'main', 'allFiles' => true]]]));
    }

    /** @return array{id: string, secret: string} */
    private function created(ResponseInterface $response): array
    {
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        return ['id' => (string) $data['publicId'], 'secret' => explode('#', (string) $data['url'])[1]];
    }

    /** @param array{id: string, secret: string} $link */
    private function exchange(array $link, ?string $visitor = null): string
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/shares/' . $link['id'] . '/exchange', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Origin', $this->settings->origin)->withHeader('Content-Type', 'application/json')
            ->withCookieParams($visitor === null ? [] : [Cookies::VISITOR => $visitor]);
        $request->getBody()->write(json_encode(['secret' => $link['secret']], JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();
        $response = $this->app()->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, preg_match('/glimpse_visitor=([a-f0-9]{64})/', $response->getHeaderLine('Set-Cookie'), $matches));
        return $matches[1] ?? '';
    }

    /** @param array{id: string, secret: string} $link
     *  @return array<string, mixed>
     */
    private function visit(array $link, string $visitor, string $query): array
    {
        $response = $this->visitRaw($link, $visitor, $query);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->data($response);
    }

    /** @param array{id: string, secret: string} $link */
    private function visitRaw(array $link, string $visitor, string $query): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/shares/' . $link['id'] . '/browse' . $query, ['REMOTE_ADDR' => '127.0.0.1']);
        parse_str($request->getUri()->getQuery(), $params);
        return $this->app()->handle($request->withQueryParams($params)->withCookieParams([Cookies::VISITOR => $visitor]));
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([Cookies::WORKSPACE => $this->owner]);
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);
        if ($body !== null) {
            $request = $request->withHeader('Origin', $this->settings->origin)->withHeader('Content-Type', 'application/json')
                ->withHeader('X-CSRF-Token', Csrf::token(UserAuth::AUDIENCE, $this->owner));
            $request->getBody()->write(json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR));
            $request->getBody()->rewind();
        }
        return $this->app()->handle($request);
    }

    /** @return \Slim\App<\Psr\Container\ContainerInterface|null> */
    private function app(): \Slim\App
    {
        return Application::create($this->settings, new FixedTransports($this->github), new FixedGitLabTransports($this->gitlab));
    }
}
