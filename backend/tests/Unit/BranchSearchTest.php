<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Failure;
use Glimpse\FailureKind;
use Glimpse\GitHub\GitHubClient;
use Glimpse\Repositories\BrowseAccess;
use Glimpse\Source\HttpResult;
use Glimpse\Tests\Fixtures\FakeTransport;
use PHPUnit\Framework\TestCase;

final class BranchSearchTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TREE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @param array<array-key, mixed> $data */
    private function response(array $data, string $link = ''): HttpResult
    {
        return new HttpResult(200, ['link' => $link], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testBranchPaginationUsesFixedEndpointsAndEncodesExactNames(): void
    {
        $transport = new FakeTransport([$this->response([['name' => 'feature/čć']], '<https://attacker.example>; rel="next"'),
            $this->response(['name' => 'feature/čć', 'commit' => ['sha' => self::SHA]])]);
        $client = new GitHubClient($transport);
        self::assertSame(['items' => ['feature/čć'], 'nextPage' => 3], $client->branches('owner', 'repo', 2));
        self::assertSame(self::SHA, $client->branchHead('owner', 'repo', 'feature/čć'));
        self::assertSame(['/repos/owner/repo/branches?per_page=100&page=2', '/repos/owner/repo/branches/feature%2F%C4%8D%C4%87'], $transport->paths);
    }

    public function testAncestryRequiresCorrectBaseAndMergeBaseInsteadOfOnlyHttpSuccess(): void
    {
        foreach (['ahead' => true, 'identical' => true, 'behind' => false, 'diverged' => false] as $status => $allowed) {
            $transport = new FakeTransport([$this->response(['status' => $status, 'base_commit' => ['sha' => self::SHA], 'merge_base_commit' => ['sha' => self::SHA]])]);
            self::assertSame($allowed, (new GitHubClient($transport))->isAncestor('owner', 'repo', self::SHA, self::TREE));
        }
        $transport = new FakeTransport([$this->response(['status' => 'ahead', 'base_commit' => ['sha' => self::SHA], 'merge_base_commit' => ['sha' => self::TREE]])]);
        self::assertFalse((new GitHubClient($transport))->isAncestor('owner', 'repo', self::SHA, self::TREE));
        $transport = new FakeTransport([$this->response(['status' => 'ahead', 'base_commit' => ['sha' => self::TREE], 'merge_base_commit' => ['sha' => self::SHA]])]);
        $this->expectException(Failure::class);
        (new GitHubClient($transport))->isAncestor('owner', 'repo', self::SHA, self::TREE);
    }

    public function testSearchPaginatesFiftyPathsAndPreservesTruncation(): void
    {
        $tree = array_map(static fn(int $index): array => ['path' => 'src/file' . $index . '.ts', 'type' => 'blob', 'mode' => '100644', 'size' => $index], range(1, 101));
        $responses = [];
        for ($i = 0; $i < 3; $i++) {
            $responses[] = $this->response(['sha' => self::SHA, 'tree' => ['sha' => self::TREE]]);
            $responses[] = $this->response(['tree' => $tree, 'truncated' => true]);
        }
        $transport = new FakeTransport($responses);
        $client = new GitHubClient($transport);
        $first = $client->search('owner', 'repo', self::SHA, 'SRC/', 1);
        $second = $client->search('owner', 'repo', self::SHA, 'SRC/', 2);
        $third = $client->search('owner', 'repo', self::SHA, 'SRC/', 3);
        self::assertCount(50, $first['items']);
        self::assertSame(2, $first['nextPage']);
        self::assertSame('src/file51.ts', $second['items'][0]['path']);
        self::assertSame(3, $second['nextPage']);
        self::assertCount(1, $third['items']);
        self::assertNull($third['nextPage']);
        self::assertTrue($third['limited']);
        self::assertSame('/repos/owner/repo/git/commits/' . self::SHA, $transport->paths[0]);
        self::assertSame('/repos/owner/repo/git/trees/' . self::TREE . '?recursive=1', $transport->paths[1]);
    }

    public function testMalformedSearchAndBranchPoliciesFailClosed(): void
    {
        $transport = new FakeTransport([]);
        foreach (['', str_repeat('x', 201), "file\0name"] as $query) {
            try {
                (new GitHubClient($transport))->search('owner', 'repo', self::SHA, $query, 1);
                self::fail('Invalid query accepted.');
            } catch (Failure $error) {
                self::assertSame(FailureKind::Invalid, $error->kind);
            }
        }
        self::assertSame([], $transport->paths);
        foreach ([null, [], ['main', 'main'], ['main..secret'], ['main~1'], ['main^'], ['a\\b'], ['a@{1}']] as $branches) {
            try {
                BrowseAccess::selected($branches);
                self::fail('Invalid policy accepted.');
            } catch (Failure $error) {
                self::assertSame(FailureKind::Invalid, $error->kind);
            }
        }
        self::assertSame('feature/čć', (new BrowseAccess(['feature/čć'], false))->branch('', 'main'));
    }
}
