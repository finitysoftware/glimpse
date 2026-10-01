<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Application;
use Glimpse\Auth\Csrf;
use Glimpse\Auth\UserAuth;
use Glimpse\Http\Cookies;
use Glimpse\Settings;
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\RepositoryTransport;
use Glimpse\Tests\Fixtures\Workspace;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class VisibilityTest extends TestCase
{
    private const PATH = '/api/workspace/repositories/fixture/alpha/visibility';
    private Settings $settings;
    private PDO $pdo;
    private RepositoryTransport $transport;
    private FixedTransports $transports;
    private string $owner;

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
        $this->owner = Workspace::signIn($this->settings, github: $this->transports);
    }

    public function testManifestHasOnlyRegularFilesAndANewBranchHasNoRules(): void
    {
        $profile = $this->get('main');
        self::assertSame([null, 0, null, false, RepositoryTransport::SHA], [$profile['mode'], $profile['version'], $profile['sourceSha'], $profile['treeChanged'], $profile['headSha']]);
        self::assertSame(['App.xaml', 'App.xaml.cs', 'README.md', 'docs/čitaj me.txt'], $profile['manifest']);
        self::assertSame([[], 4, false], [$profile['selected'], $profile['fileCount'], $profile['truncated']]);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM repositories'));
    }

    public function testSelectionsAreSavedPerBranchWithExplicitFolderExpansion(): void
    {
        $saved = $this->save('main', 0, [['action' => 'select', 'path' => 'README.md'], ['action' => 'select', 'path' => 'docs']]);
        self::assertSame(['selected', 1, RepositoryTransport::SHA, ['README.md', 'docs/čitaj me.txt']], [$saved['mode'], $saved['version'], $saved['sourceSha'], $saved['selected']]);
        // Branches never inherit each other's rules.
        $feature = $this->get('feature/preview');
        self::assertSame([null, []], [$feature['mode'], $feature['selected']]);
        $other = $this->save('feature/preview', 0, [['action' => 'select', 'path' => 'App.xaml']], sha: str_repeat('c', 40));
        self::assertSame(['App.xaml'], $other['selected']);
        self::assertSame(['README.md', 'docs/čitaj me.txt'], $this->get('main')['selected']);
        $updated = $this->save('main', 1, [['action' => 'deselect', 'path' => 'docs'], ['action' => 'select', 'path' => 'App.xaml.cs']]);
        self::assertSame([2, ['App.xaml.cs', 'README.md']], [$updated['version'], $updated['selected']]);
        self::assertSame(2, (int) $this->scalar("SELECT selected_count FROM repository_visibility_profiles WHERE branch = 'main'"));
        self::assertSame(32, strlen((string) $this->scalar("SELECT path_hash FROM repository_visibility_files WHERE path = 'README.md'")));
        $audit = (string) json_encode($this->column("SELECT metadata FROM audit_events WHERE action = 'visibility_profile_saved'"));
        self::assertStringNotContainsString('README', $audit);
        self::assertSame(3, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'visibility_profile_saved' AND repository_id IS NOT NULL"));
        $cleared = $this->save('main', 2, [['action' => 'deselect', 'path' => '']]);
        self::assertSame([], $cleared['selected']);
    }

    public function testAllFilesIsAnExplicitModeAndSwitchingBackStartsEmpty(): void
    {
        $this->save('main', 0, [['action' => 'select', 'path' => 'README.md']]);
        $all = $this->save('main', 1, [], 'all');
        self::assertSame(['all', [], 0], [$all['mode'], $all['selected'], (int) $this->scalar('SELECT COUNT(*) FROM repository_visibility_files')]);
        self::assertSame(400, $this->send('PUT', self::PATH, ['branch' => 'main', 'expectedVersion' => 2, 'sourceSha' => RepositoryTransport::SHA,
            'mode' => 'all', 'operations' => [['action' => 'select', 'path' => 'README.md']]])->getStatusCode());
        $selected = $this->save('main', 2, [['action' => 'select', 'path' => 'App.xaml']], 'selected');
        self::assertSame(['selected', ['App.xaml']], [$selected['mode'], $selected['selected']]);
    }

    public function testPathsAreExactCaseSensitiveAndBoundedToWholeSegments(): void
    {
        foreach (['App', 'readme.md', 'docs-link', 'docs-submodule', 'docs/missing.txt'] as $path) {
            $response = $this->put('main', 0, [['action' => 'select', 'path' => $path]]);
            self::assertSame([409, 'visibility_path_unavailable'], [$response->getStatusCode(), $this->data($response)['error']['code']], $path);
        }
        foreach (['../README.md', '/README.md', 'docs//x', 'docs/./x', "READ\0ME.md", 'docs\\x', str_repeat('a', 1025)] as $path) {
            self::assertSame(400, $this->put('main', 0, [['action' => 'select', 'path' => $path]])->getStatusCode(), $path);
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM repository_visibility_profiles'));
    }

    public function testStaleVersionsMovedBranchesAndIncompleteTreesNeverSavePartially(): void
    {
        $this->save('main', 0, [['action' => 'select', 'path' => 'README.md']]);
        $stale = $this->put('main', 0, [['action' => 'select', 'path' => 'App.xaml']]);
        self::assertSame([409, 'visibility_changed'], [$stale->getStatusCode(), $this->data($stale)['error']['code']]);
        $this->transport->head = str_repeat('e', 40);
        $moved = $this->put('main', 1, [['action' => 'select', 'path' => 'App.xaml']], sha: RepositoryTransport::SHA);
        self::assertSame([409, 'visibility_tree_changed'], [$moved->getStatusCode(), $this->data($moved)['error']['code']]);
        // The saved path stays a path rule after it disappears from the head.
        $this->transport->treeExclude = ['README.md'];
        $profile = $this->get('main');
        self::assertSame([true, ['README.md'], ['README.md']], [$profile['treeChanged'], $profile['selected'], $profile['missing']]);
        $this->transport->head = str_repeat('d', 40);
        $this->transport->truncatedTree = true;
        $truncated = $this->put('main', 1, [['action' => 'select', 'path' => 'App.xaml']], sha: str_repeat('d', 40));
        self::assertSame([409, 'visibility_tree_incomplete'], [$truncated->getStatusCode(), $this->data($truncated)['error']['code']]);
        self::assertNull($this->get('main')['manifest']);
        self::assertSame(['all', 2], array_values(array_intersect_key($this->save('main', 1, [], 'all', str_repeat('d', 40)), ['mode' => 1, 'version' => 1])));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM repository_visibility_files'));
    }

    public function testRequestLimitsAndOwnershipAreEnforced(): void
    {
        $operations = array_fill(0, 101, ['action' => 'select', 'path' => 'README.md']);
        self::assertSame(400, $this->put('main', 0, $operations)->getStatusCode());
        $large = array_fill(0, 40, ['action' => 'deselect', 'path' => str_repeat('d', 1000)]);
        self::assertSame(413, $this->put('main', 0, $large)->getStatusCode());
        // The larger body is allowed only on this route.
        $medium = array_fill(0, 6, ['action' => 'deselect', 'path' => str_repeat('d', 1000)]);
        self::assertSame(200, $this->put('main', 0, $medium)->getStatusCode());
        self::assertSame(413, $this->send('POST', '/api/workspace/repositories/fixture/alpha/shares', ['name' => str_repeat('n', 5000)])->getStatusCode());
        self::assertSame(403, $this->send('PUT', self::PATH, ['branch' => 'main'], csrf: 'wrong')->getStatusCode());
        $other = Workspace::signIn($this->settings, 'other.user', github: $this->transports);
        $theirs = $this->send('GET', self::PATH . '?branch=main', session: $other);
        self::assertSame([null, 0, []], [$this->data($theirs)['mode'], $this->data($theirs)['version'], $this->data($theirs)['selected']]);
        self::assertSame(401, $this->send('GET', self::PATH . '?branch=main', session: '')->getStatusCode());
        self::assertSame(400, $this->send('GET', self::PATH . '?branch=' . rawurlencode('bad..branch'))->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function get(string $branch): array
    {
        $response = $this->send('GET', self::PATH . '?branch=' . rawurlencode($branch));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->data($response);
    }

    /** @param list<array{action: string, path: string}> $operations
     *  @return array<string, mixed>
     */
    private function save(string $branch, int $version, array $operations, ?string $mode = null, ?string $sha = null): array
    {
        $response = $this->put($branch, $version, $operations, $mode, $sha);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->data($response);
    }

    /** @param list<array{action: string, path: string}> $operations */
    private function put(string $branch, int $version, array $operations, ?string $mode = null, ?string $sha = null): ResponseInterface
    {
        return $this->send('PUT', self::PATH, ['branch' => $branch, 'expectedVersion' => $version, 'sourceSha' => $sha ?? $this->transport->head,
            'operations' => $operations] + ($mode === null ? [] : ['mode' => $mode]));
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        return array_values($read->fetchAll(PDO::FETCH_COLUMN));
    }

    private function scalar(string $sql): mixed
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        return $read->fetchColumn();
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, ?string $csrf = null, ?string $session = null): ResponseInterface
    {
        $session ??= $this->owner;
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query)->withCookieParams($session === '' ? [] : [Cookies::WORKSPACE => $session]);
        if ($body !== null) {
            $request = $request->withHeader('Origin', $this->settings->origin)->withHeader('Content-Type', 'application/json')
                ->withHeader('X-CSRF-Token', $csrf ?? Csrf::token(UserAuth::AUDIENCE, $session));
            $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $request->getBody()->rewind();
        }
        return Application::create($this->settings, $this->transports)->handle($request);
    }
}
