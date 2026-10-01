<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Failure;
use Glimpse\GitHub\GitHubClient;
use Glimpse\Source\HttpResult;
use Glimpse\Tests\Fixtures\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitHubClientTest extends TestCase
{
    public function testCatalogueUsesFixedPaginationAndDoesNotExposeUpstreamFields(): void
    {
        $transport = new FakeTransport([
            new HttpResult(200, ['link' => '<https://attacker.example/next>; rel="next"'], '[{"id":42,"full_name":"owner/repo","private":true,"default_branch":"main","description":null,"permissions":{"admin":true}}]'),
            new HttpResult(200, [], '[]'),
        ]);
        $client = new GitHubClient($transport);
        $first = $client->repositories(1);
        self::assertSame(2, $first['nextPage']);
        self::assertSame('owner/repo', $first['items'][0]['fullName']);
        self::assertArrayNotHasKey('permissions', $first['items'][0]);
        self::assertSame(['items' => [], 'nextPage' => null], $client->repositories(2));
        self::assertSame('/user/repos?per_page=30&page=2&sort=full_name&direction=asc', $transport->paths[1]);
    }

    public function testCatalogueRejectsInvalidPageBeforeNetworkRequest(): void
    {
        $transport = new FakeTransport([]);
        $this->expectException(Failure::class);
        try {
            (new GitHubClient($transport))->repositories(0);
        } finally {
            self::assertSame([], $transport->paths);
        }
    }

    public function testDirectoryEncodesUnicodeAndSortsFoldersBeforeFiles(): void
    {
        $transport = new FakeTransport([new HttpResult(200, [], json_encode([
            ['name' => 'z.txt', 'path' => 'čitaj me/z.txt', 'type' => 'file', 'size' => 2],
            ['name' => 'vendor', 'path' => 'čitaj me/vendor', 'type' => 'file', 'size' => 0, 'submodule_git_url' => 'https://attacker.example'],
            ['name' => 'src', 'path' => 'čitaj me/src', 'type' => 'dir', 'size' => 0],
        ], JSON_THROW_ON_ERROR))]);
        $data = (new GitHubClient($transport))->directory('owner', 'repo', 'čitaj me', str_repeat('a', 40));
        self::assertSame(['src', 'vendor', 'z.txt'], array_column($data['entries'], 'name'));
        self::assertSame('submodule', $data['entries'][1]['type']);
        self::assertFalse($data['limited']);
        self::assertSame('/repos/owner/repo/contents/%C4%8Ditaj%20me?ref=' . str_repeat('a', 40), $transport->paths[0]);
    }

    public function testDirectoryReportsGithubLimitInsteadOfPretendingItIsComplete(): void
    {
        $items = [];
        for ($i = 0; $i < 1000; $i++) {
            $items[] = ['name' => 'file' . $i, 'path' => 'file' . $i, 'type' => 'file', 'size' => 0];
        }
        $transport = new FakeTransport([new HttpResult(200, [], json_encode($items, JSON_THROW_ON_ERROR))]);
        self::assertTrue((new GitHubClient($transport))->directory('owner', 'repo', '', str_repeat('a', 40))['limited']);
    }

    public function testDirectoryRejectsMismatchedChildPath(): void
    {
        $transport = new FakeTransport([new HttpResult(200, [], '[{"name":"secret","path":"../secret","type":"file","size":1}]')]);
        $this->expectException(Failure::class);
        (new GitHubClient($transport))->directory('owner', 'repo', '', str_repeat('a', 40));
    }

    public function testProbeEncodesBranchAndUnicodePathAndPinsFileToCommit(): void
    {
        $sha = str_repeat('a', 40);
        $transport = new FakeTransport([
            new HttpResult(200, [], '{"id":42,"full_name":"owner/example","private":true,"default_branch":"main","token":"must-not-escape"}'),
            new HttpResult(200, [], json_encode(['sha' => $sha], JSON_THROW_ON_ERROR)),
            new HttpResult(200, [], json_encode(['type' => 'file', 'path' => 'docs/čitaj me.txt', 'encoding' => 'base64', 'size' => 5, 'content' => base64_encode('hello')], JSON_THROW_ON_ERROR)),
        ]);
        $client = new GitHubClient($transport);
        $metadata = $client->metadata('owner', 'example');
        self::assertArrayNotHasKey('token', $metadata);
        self::assertSame($sha, $client->resolveCommit('owner', 'example', 'feature/test'));
        self::assertSame('hello', $client->file('owner', 'example', 'docs/čitaj me.txt', $sha)['content']);
        self::assertSame('/repos/owner/example/commits/feature%2Ftest', $transport->paths[1]);
        self::assertSame('/repos/owner/example/contents/docs/%C4%8Ditaj%20me.txt?ref=' . $sha, $transport->paths[2]);
        self::assertNotEmpty($transport->timeouts);
        foreach ($transport->timeouts as $timeout) {
            self::assertLessThanOrEqual(15000, $timeout);
        }
        self::assertLessThanOrEqual($transport->timeouts[0], $transport->timeouts[2]);
    }

    /** @return iterable<string, array{int, array<string, string>, string}> */
    public static function upstreamErrors(): iterable
    {
        yield 'invalid credentials' => [401, [], 'github_credentials_rejected'];
        yield 'denied' => [403, [], 'github_access_denied'];
        yield 'missing' => [404, [], 'github_not_found'];
        yield 'empty repository' => [409, [], 'github_empty_repository'];
        yield 'quota' => [403, ['x-ratelimit-remaining' => '0'], 'github_rate_limited'];
        yield 'secondary limit' => [403, ['retry-after' => '30'], 'github_rate_limited'];
        yield 'rate limit' => [429, ['retry-after' => '30'], 'github_rate_limited'];
        yield 'redirect refused' => [302, ['location' => 'https://attacker.example'], 'github_unavailable'];
        yield 'upstream failure' => [500, [], 'github_unavailable'];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('upstreamErrors')]
    public function testErrorsAreClassifiedWithoutLeakingUpstreamBodyOrRetrying(int $status, array $headers, string $code): void
    {
        $transport = new FakeTransport([new HttpResult($status, $headers, 'private upstream details')]);
        try {
            (new GitHubClient($transport))->metadata('owner', 'example');
            self::fail('Expected upstream error.');
        } catch (Failure $error) {
            self::assertSame($code, $error->errorCode);
            self::assertStringNotContainsString('private upstream details', $error->getMessage());
            self::assertCount(1, $transport->paths);
            if ($status === 429) {
                self::assertSame(30, $error->retryAfter);
            }
        }
    }

    public function testRejectsRepositoryTraversalBeforeNetworkRequest(): void
    {
        $transport = new FakeTransport([]);
        $this->expectException(Failure::class);
        try {
            (new GitHubClient($transport))->metadata('owner', '..');
        } finally {
            self::assertSame([], $transport->paths);
        }
    }

    public function testRejectsPathTraversalBeforeNetworkRequest(): void
    {
        $transport = new FakeTransport([]);
        $this->expectException(Failure::class);
        try {
            (new GitHubClient($transport))->file('owner', 'repo', '../secret', str_repeat('a', 40));
        } finally {
            self::assertSame([], $transport->paths);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFiles(): iterable
    {
        yield 'binary' => ["hello\0", 'binary_file'];
        yield 'invalid utf8' => ["\xff", 'binary_file'];
        yield 'too many lines' => [str_repeat("x\n", 20001), 'file_too_large'];
        yield 'oversized' => [str_repeat('x', 1048577), 'file_too_large'];
    }

    #[DataProvider('invalidFiles')]
    public function testFileLimits(string $content, string $code): void
    {
        $transport = new FakeTransport([new HttpResult(200, [], json_encode(['type' => 'file', 'path' => 'file.txt', 'encoding' => 'base64', 'size' => strlen($content), 'content' => base64_encode($content)], JSON_THROW_ON_ERROR))]);
        try {
            (new GitHubClient($transport))->file('owner', 'repo', 'file.txt', str_repeat('a', 40));
            self::fail('Expected file error.');
        } catch (Failure $error) {
            self::assertSame($code, $error->errorCode);
        }
    }

    public function testRejectsOversizedUpstreamBeforeJsonDecode(): void
    {
        $transport = new FakeTransport([new HttpResult(200, [], str_repeat('a', 4 * 1024 * 1024 + 1))]);
        try {
            (new GitHubClient($transport))->metadata('owner', 'repo');
            self::fail('Expected size error.');
        } catch (Failure $error) {
            self::assertSame('github_response_too_large', $error->errorCode);
        }
    }

    public function testRejectsMalformedJson(): void
    {
        $transport = new FakeTransport([new HttpResult(200, [], '{')]);
        try {
            (new GitHubClient($transport))->metadata('owner', 'repo');
            self::fail('Expected format error.');
        } catch (Failure $error) {
            self::assertSame('github_invalid_response', $error->errorCode);
        }
    }

    public function testTimeoutDoesNotRetry(): void
    {
        $transport = new FakeTransport([Failure::upstream('github_timeout', 'GitHub did not respond in time.')]);
        $this->expectException(Failure::class);
        try {
            (new GitHubClient($transport))->metadata('owner', 'repo');
        } finally {
            self::assertCount(1, $transport->paths);
        }
    }
}
