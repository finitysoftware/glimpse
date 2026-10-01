<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Failure;
use Glimpse\FailureKind;
use Glimpse\GitHub\GitHubClient;
use Glimpse\Source\CommitData;
use Glimpse\Source\HttpResult;
use Glimpse\Tests\Fixtures\FakeTransport;
use PHPUnit\Framework\TestCase;

final class CommitHistoryTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return array<string, mixed> */
    private function commit(): array
    {
        return ['sha' => self::SHA, 'commit' => ['message' => "Subject\n\n<script>text</script>", 'author' => ['name' => 'Author', 'date' => '2026-09-14T00:00:00Z']],
            'parents' => [], 'stats' => ['additions' => 1, 'deletions' => 1, 'total' => 2], 'files' => [], 'html_url' => 'https://private.example'];
    }

    /** @return array<string, mixed> */
    private function file(): array
    {
        return ['filename' => 'docs/čitaj me.txt', 'previous_filename' => 'old name.txt', 'status' => 'renamed',
            'additions' => 1, 'deletions' => 1, 'patch' => "@@ -2 +2 @@\n-old\n+new"];
    }

    /** @param array<array-key, mixed> $data */
    private function response(array $data, string $link = ''): HttpResult
    {
        return new HttpResult(200, ['link' => $link], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testHistoryIsPinnedEncodedAndPaginatesWithoutFollowingUntrustedUrls(): void
    {
        $transport = new FakeTransport([$this->response([$this->commit()], '<https://attacker.example/secrets>; rel="next"'), $this->response([])]);
        $client = new GitHubClient($transport);
        $result = $client->history('owner', 'repo', self::SHA, 'docs/čitaj me.txt', 3500);
        self::assertSame(3501, $result['nextPage']);
        self::assertSame([], $result['items'][0]['parents']);
        self::assertArrayNotHasKey('html_url', $result['items'][0]);
        self::assertSame('/repos/owner/repo/commits?sha=' . self::SHA . '&path=docs%2F%C4%8Ditaj%20me.txt&per_page=30&page=3500', $transport->paths[0]);
        self::assertSame([], $client->history('owner', 'repo', self::SHA, '', 3501)['items']);
        self::assertCount(2, $transport->paths);
    }

    public function testMalformedHistoryQueryIsRejectedBeforeNetworkAccess(): void
    {
        $transport = new FakeTransport([]);
        foreach ([['main', '', 1], [self::SHA, '../secret', 1], [self::SHA, "a\0b", 1], [self::SHA, '', 0]] as [$sha, $path, $page]) {
            try {
                (new GitHubClient($transport))->history('owner', 'repo', $sha, $path, $page);
                self::fail('Invalid query accepted.');
            } catch (Failure $error) {
                self::assertSame(FailureKind::Invalid, $error->kind);
            }
        }
        self::assertSame([], $transport->paths);
    }

    public function testCommitPagePreservesRenameAndRootContextAndOmitsRawUrls(): void
    {
        $commit = $this->commit();
        $commit['files'] = [$this->file() + ['raw_url' => 'https://secret.example']];
        $transport = new FakeTransport([$this->response($commit, '<https://api.github.com/next>; rel="next"')]);
        $result = (new GitHubClient($transport))->commit('owner', 'repo', self::SHA, 1);
        self::assertSame(2, $result['nextPage']);
        self::assertSame('old name.txt', $result['files'][0]['previousPath']);
        self::assertSame('complete', $result['files'][0]['patchState']);
        self::assertArrayNotHasKey('raw_url', $result['files'][0]);
        self::assertSame('/repos/owner/repo/commits/' . self::SHA . '?per_page=30&page=1', $transport->paths[0]);
    }

    public function testMissingTruncatedAndOversizedPatchesRemainListedExplicitly(): void
    {
        $commit = $this->commit();
        $file = $this->file();
        $commit['files'] = [array_replace($file, ['patch' => null]), array_replace($file, ['patch' => "@@ -1,2 +1,2 @@\n-a\n+b"]),
            array_replace($file, ['patch' => str_repeat('x', 1048577)]), array_replace($file, ['patch' => str_repeat("+x\n", 20001)])];
        $result = CommitData::detail($commit, self::SHA, 1, false);
        self::assertSame(['unavailable', 'incomplete', 'limited', 'limited'], array_column($result['files'], 'patchState'));
        self::assertNull($result['files'][2]['patch']);
        self::assertCount(4, $result['files']);
    }

    public function testPatchBudgetIsSharedAcrossFilesAndLastGithubPageIsMarked(): void
    {
        $commit = $this->commit();
        $commit['files'] = array_fill(0, 30, array_replace($this->file(), ['patch' => str_repeat('x', 50000)]));
        $result = CommitData::detail($commit, self::SHA, 100, true);
        self::assertTrue($result['filesLimited']);
        self::assertNull($result['nextPage']);
        self::assertSame('limited', $result['files'][29]['patchState']);
        self::assertLessThanOrEqual(1048576, array_sum(array_map(static fn(array $file): int => strlen($file['patch'] ?? ''), $result['files'])));
    }

    public function testMergeParentsAndNoNewlineMarkerArePreserved(): void
    {
        $commit = $this->commit();
        $commit['parents'] = [['sha' => str_repeat('b', 40)], ['sha' => str_repeat('c', 40)]];
        $commit['files'] = [array_replace($this->file(), ['patch' => "@@ -1 +1 @@\n-old\n\\ No newline at end of file\n+new\n\\ No newline at end of file"])];
        $result = CommitData::detail($commit, self::SHA, 1, false);
        self::assertCount(2, $result['commit']['parents']);
        self::assertSame('complete', $result['files'][0]['patchState']);
    }

    public function testMismatchedCommitAndUnsafeFilenameAreRejected(): void
    {
        $this->expectException(Failure::class);
        CommitData::detail($this->commit(), str_repeat('b', 40), 1, false);
    }

    public function testTraversalInChangedFileIsRejected(): void
    {
        $commit = $this->commit();
        $commit['files'] = [array_replace($this->file(), ['filename' => '../secret'])];
        $this->expectException(Failure::class);
        CommitData::detail($commit, self::SHA, 1, false);
    }
}
