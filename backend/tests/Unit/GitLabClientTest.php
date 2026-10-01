<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Failure;
use Glimpse\GitLab\GitLabCachePolicy;
use Glimpse\GitLab\GitLabClient;
use Glimpse\Source\DerivedCache;
use Glimpse\Source\HttpResult;
use Glimpse\Tests\Fixtures\FakeTransport;
use Glimpse\Tests\Fixtures\GitLabRepositoryTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitLabClientTest extends TestCase
{
    private const SHA = GitLabRepositoryTransport::SHA;

    public function testIdentityTokenDetailsAndProjectCatalogue(): void
    {
        $upstream = new GitLabRepositoryTransport();
        $client = new GitLabClient($upstream);
        self::assertSame(['id' => 5150, 'username' => 'fixture-gitlab'], $client->user());
        self::assertSame(['scopes' => ['read_api'], 'expiresAt' => '2027-03-01', 'active' => true], $client->tokenInfo());
        $first = $client->projects(1);
        self::assertSame(2, $first['nextPage']);
        self::assertSame([
            ['id' => 42, 'fullName' => 'group/sub/alpha', 'owner' => 'group/sub', 'name' => 'alpha', 'private' => true, 'visibility' => 'private', 'defaultBranch' => 'main', 'description' => 'GitLab project for browser verification'],
            ['id' => 43, 'fullName' => 'group/beta', 'owner' => 'group', 'name' => 'beta', 'private' => true, 'visibility' => 'internal', 'defaultBranch' => 'main', 'description' => ''],
        ], $first['items']);
        $second = $client->projects(2);
        self::assertSame([null, false, 'public'], [$second['nextPage'], $second['items'][0]['private'], $second['items'][0]['visibility']]);
        self::assertSame('/projects?membership=true&min_access_level=20&order_by=path&sort=asc&per_page=30&page=2', $upstream->paths[3]);
        $upstream->scopes = null;
        self::assertNull($client->tokenInfo());
        $this->expectException(Failure::class);
        $client->projects(0);
    }

    public function testCatalogueLeavesOutNamespacesLongerThanTheLocalColumnsAndRejectsMalformedProjects(): void
    {
        $long = str_repeat('g', 200) . '/' . str_repeat('h', 100);
        $project = static fn(array $overrides): array => $overrides + ['id' => 7, 'path' => 'app', 'path_with_namespace' => $long . '/app', 'visibility' => 'private', 'default_branch' => 'main'];
        $transport = new FakeTransport([new HttpResult(200, [], (string) json_encode([$project([]), $project(['id' => 8, 'path_with_namespace' => 'short/app'])]))]);
        self::assertSame(['short/app'], array_column((new GitLabClient($transport))->projects(1)['items'], 'fullName'));
        foreach ([['path_with_namespace' => 'app'], ['path_with_namespace' => 'group/other'], ['path_with_namespace' => 'group/../app'], ['visibility' => 'secret'],
            ['id' => '7'], ['default_branch' => 'bad..branch'], ['path_with_namespace' => 'group/ap p', 'path' => 'ap p']] as $bad) {
            $transport = new FakeTransport([new HttpResult(200, [], (string) json_encode([$project($bad + ['path_with_namespace' => 'group/app'])]))]);
            try {
                (new GitLabClient($transport))->projects(1);
                self::fail('Malformed project accepted: ' . json_encode($bad));
            } catch (Failure $error) {
                self::assertSame('gitlab_invalid_response', $error->errorCode);
            }
        }
    }

    public function testMetadataBranchesRefsAndAncestry(): void
    {
        $upstream = new GitLabRepositoryTransport();
        $client = new GitLabClient($upstream);
        self::assertSame(['id' => 42, 'fullName' => 'group/sub/alpha', 'owner' => 'group/sub', 'name' => 'alpha', 'private' => true, 'visibility' => 'private', 'defaultBranch' => 'main'], $client->metadata(42));
        self::assertSame(['items' => ['main', 'feature/preview', 'private'], 'nextPage' => null], $client->branches(42, 1));
        self::assertSame(str_repeat('c', 40), $client->branchHead(42, 'feature/preview'));
        self::assertContains('/projects/42/repository/branches/feature%2Fpreview', $upstream->paths);
        self::assertSame(self::SHA, $client->resolveCommit(42, 'main'));
        self::assertTrue($client->isAncestor(42, str_repeat('b', 40), self::SHA));
        self::assertFalse($client->isAncestor(42, str_repeat('e', 40), self::SHA));
        $upstream->diverged = true;
        self::assertFalse($client->isAncestor(42, str_repeat('b', 40), self::SHA));
        self::assertTrue($client->isAncestor(42, self::SHA, self::SHA));
        try {
            $client->metadata(41);
            self::fail('Missing project returned.');
        } catch (Failure $error) {
            self::assertSame('gitlab_not_found', $error->errorCode);
        }
        $empty = new FakeTransport([new HttpResult(200, [], '{"id":9,"path":"empty","path_with_namespace":"group/empty","visibility":"private","default_branch":null,"empty_repo":true}')]);
        try {
            (new GitLabClient($empty))->metadata(9);
            self::fail('Empty repository returned.');
        } catch (Failure $error) {
            self::assertSame('gitlab_empty_repository', $error->errorCode);
        }
        $sha256 = new FakeTransport([new HttpResult(200, [], '{"name":"main","commit":{"id":"' . str_repeat('a', 64) . '"}}')]);
        try {
            (new GitLabClient($sha256))->branchHead(9, 'main');
            self::fail('SHA-256 repository accepted.');
        } catch (Failure $error) {
            self::assertSame('gitlab_unsupported_repository', $error->errorCode);
        }
    }

    public function testDirectoriesAndManifestMapTreeEntries(): void
    {
        $upstream = new GitLabRepositoryTransport();
        $client = new GitLabClient($upstream);
        $root = $client->directory(42, '', self::SHA);
        self::assertFalse($root['limited']);
        self::assertSame(
            [['docs', 'dir'], ['App.xaml', 'file'], ['App.xaml.cs', 'file'], ['docs-link', 'symlink'], ['docs-submodule', 'submodule'], ['README.md', 'file']],
            array_map(static fn(array $entry): array => [$entry['name'], $entry['type']], $root['entries']),
        );
        self::assertNull($root['entries'][1]['size']);
        self::assertSame([['name' => 'čitaj me.txt', 'path' => 'docs/čitaj me.txt', 'type' => 'file', 'size' => null]], $client->directory(42, 'docs', self::SHA)['entries']);
        self::assertSame('/projects/42/repository/tree?path=docs&ref=' . self::SHA . '&per_page=100&page=1', end($upstream->paths));
        $manifest = $client->files(42, self::SHA);
        self::assertFalse($manifest['truncated']);
        self::assertSame(['App.xaml.cs', 'App.xaml', 'README.md', 'docs/čitaj me.txt'], array_keys($manifest['files']));
        foreach (['a/../b', 'docs\\x', "docs\n"] as $path) {
            try {
                $client->directory(42, $path, self::SHA);
                self::fail('Unsafe path accepted.');
            } catch (Failure $error) {
                self::assertSame('invalid_path', $error->errorCode);
            }
        }
    }

    public function testLargeTreesAreCutAtThePageLimitAndCachedAsOneEntry(): void
    {
        $upstream = new GitLabRepositoryTransport();
        $upstream->extraFiles = GitLabClient::TREE_PAGES * 100 + 5;
        $cache = new class implements DerivedCache {
            /** @var array<string, string> */
            public array $entries = [];
            public function derived(string $name): ?string
            {
                return $this->entries[$name] ?? null;
            }
            public function storeDerived(string $name, string $body, int $ttl): void
            {
                $this->entries[$name] = $body;
            }
        };
        $manifest = (new GitLabClient($upstream, $cache))->files(42, self::SHA);
        self::assertTrue($manifest['truncated']);
        self::assertCount(GitLabClient::TREE_PAGES, $upstream->paths);
        self::assertCount(1, $cache->entries);
        $calls = count($upstream->paths);
        $again = (new GitLabClient($upstream, $cache))->files(42, self::SHA);
        self::assertSame([$manifest['files'], true], [$again['files'], $again['truncated']]);
        self::assertCount($calls, $upstream->paths);
        $duplicate = new FakeTransport([new HttpResult(200, [], (string) json_encode([
            ['id' => str_repeat('1', 40), 'name' => 'a', 'type' => 'blob', 'path' => 'a', 'mode' => '100644'],
            ['id' => str_repeat('2', 40), 'name' => 'a', 'type' => 'blob', 'path' => 'a', 'mode' => '100644'],
        ]))]);
        try {
            (new GitLabClient($duplicate))->files(42, self::SHA);
            self::fail('Duplicate paths accepted.');
        } catch (Failure $error) {
            self::assertSame('gitlab_invalid_response', $error->errorCode);
        }
    }

    public function testHistoryAndCommitsHaveGitHubShapes(): void
    {
        $upstream = new GitLabRepositoryTransport();
        $client = new GitLabClient($upstream);
        $history = $client->history(42, self::SHA, 'docs', 1);
        self::assertSame([2, false], [$history['nextPage'], $history['limited']]);
        self::assertSame(['sha' => self::SHA, 'message' => 'Improve application startup', 'messageLimited' => false, 'author' => 'Alex Doe',
            'date' => '2026-09-14T07:24:00Z', 'parents' => [str_repeat('b', 40)]], $history['items'][0]);
        self::assertSame('/projects/42/repository/commits?ref_name=' . self::SHA . '&path=docs&per_page=30&page=1', end($upstream->paths));
        $detail = $client->commit(42, self::SHA, 1);
        self::assertSame(['additions' => 7, 'deletions' => 3, 'total' => 10], $detail['stats']);
        self::assertSame(2, $detail['nextPage']);
        self::assertSame([
            ['App.xaml.cs', null, 'modified', 6, 1, 'complete'],
            ['docs/čitaj me.txt', 'old name.txt', 'renamed', 1, 1, 'complete'],
            ['logo.png', null, 'modified', 0, 0, 'unavailable'],
            ['huge.sql', null, 'added', 0, 0, 'limited'],
        ], array_map(static fn(array $file): array => [$file['path'], $file['previousPath'], $file['status'], $file['additions'], $file['deletions'], $file['patchState']], $detail['files']));
        self::assertStringStartsWith('@@ -1 +1 @@', (string) $detail['files'][1]['patch']);
        $last = $client->commitFiles(42, self::SHA, 2);
        self::assertSame([false, 'README.md', 'removed', 1], [$last['hasNext'], $last['files'][0]['path'], $last['files'][0]['status'], $last['files'][0]['deletions']]);
    }

    public function testFilesAreDecodedAndLimitedLikeGitHub(): void
    {
        $upstream = new GitLabRepositoryTransport();
        $file = (new GitLabClient($upstream))->file(42, 'docs/čitaj me.txt', self::SHA);
        self::assertSame(['docs/čitaj me.txt', self::SHA], [$file['path'], $file['commitSha']]);
        self::assertStringContainsString('čćžšđ', $file['content']);
        self::assertSame('/projects/42/repository/files/docs%2F%C4%8Ditaj%20me.txt?ref=' . self::SHA, end($upstream->paths));
        $binary = new FakeTransport([new HttpResult(200, [], (string) json_encode(['file_path' => 'logo.png', 'size' => 4, 'encoding' => 'base64', 'content' => base64_encode("\x89PNG")]))]);
        try {
            (new GitLabClient($binary))->file(42, 'logo.png', self::SHA);
            self::fail('Binary file returned as text.');
        } catch (Failure $error) {
            self::assertSame('binary_file', $error->errorCode);
        }
    }

    /** @return iterable<string, array{int, array<string, string>, string}> */
    public static function failures(): iterable
    {
        yield 'rejected token' => [401, [], 'gitlab_credentials_rejected'];
        yield 'scope or permission' => [403, [], 'gitlab_access_denied'];
        yield 'missing project' => [404, [], 'gitlab_not_found'];
        yield 'rate limit' => [429, ['retry-after' => '30'], 'gitlab_rate_limited'];
        yield 'quota header' => [403, ['ratelimit-remaining' => '0', 'ratelimit-reset' => (string) (time() + 60)], 'gitlab_rate_limited'];
        yield 'server error' => [502, [], 'gitlab_unavailable'];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('failures')]
    public function testUpstreamFailuresMapToGitLabErrors(int $status, array $headers, string $code): void
    {
        try {
            (new GitLabClient(new FakeTransport([new HttpResult($status, $headers, '{"message":"private upstream text"}')])))->metadata(42);
            self::fail('Failure returned data.');
        } catch (Failure $error) {
            self::assertSame($code, $error->errorCode);
            self::assertStringNotContainsString('private upstream text', $error->getMessage());
        }
    }

    public function testCachePolicyKeepsShaAddressedDataAndSkipsTreePages(): void
    {
        $policy = new GitLabCachePolicy();
        $rule = static fn(string $path): array => [($r = $policy->rule($path))->content, $r->metadata, $r->immutable, $r->store];
        $sha = self::SHA;
        self::assertSame([false, true, false, true], $rule('/projects?membership=true&min_access_level=20&order_by=path&sort=asc&per_page=30&page=1'));
        self::assertSame([false, true, false, true], $rule('/projects/42'));
        self::assertSame([true, false, true, true], $rule('/projects/42/repository/tree?path=docs&ref=' . $sha . '&per_page=100&page=1'));
        self::assertSame([true, false, true, false], $rule('/projects/42/repository/tree?ref=' . $sha . '&recursive=true&per_page=100&page=3'));
        self::assertSame([true, false, true, true], $rule('/projects/42/repository/files/README.md?ref=' . $sha));
        self::assertSame([true, false, true, true], $rule('/projects/42/repository/commits?ref_name=' . $sha . '&per_page=30&page=1'));
        self::assertSame([true, false, true, true], $rule('/projects/42/repository/commits/' . $sha . '?stats=true'));
        self::assertSame([true, false, true, true], $rule('/projects/42/repository/commits/' . $sha . '/diff?per_page=30&page=2'));
        self::assertSame([false, false, true, true], $rule('/projects/42/repository/merge_base?refs%5B%5D=' . $sha . '&refs%5B%5D=' . str_repeat('b', 40)));
        self::assertSame([false, false, false, true], $rule('/projects/42/repository/branches/main'));
        self::assertSame([false, false, false, true], $rule('/projects/42/repository/commits/main'));
        self::assertSame([false, false, false, true], $rule('/projects/42/repository/tree?ref=main&per_page=100&page=1'));
    }
}
