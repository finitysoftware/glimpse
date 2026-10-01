<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Closure;
use Glimpse\Database\Connection;
use Glimpse\Failure;
use Glimpse\FailureKind;
use Glimpse\Repositories\BranchChoices;
use Glimpse\Repositories\BrowseAccess;
use Glimpse\Repositories\BrowseQuery;
use Glimpse\Repositories\Preview;
use Glimpse\Repositories\RepositoryBrowser;
use Glimpse\Settings;
use Glimpse\Sharing\SharePolicy;
use Glimpse\Source\Provider;
use Glimpse\Tests\Fixtures\FakeRepository;
use Glimpse\Tests\Fixtures\FixedFilePolicy;
use PHPUnit\Framework\TestCase;

/** What the owner and a link visitor may read of a repository, checked without a database, cache or platform. */
final class BrowseRulesTest extends TestCase
{
    private const MAIN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OLDER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const DEV = 'cccccccccccccccccccccccccccccccccccccccc';
    private const SECRET = 'dddddddddddddddddddddddddddddddddddddddd';
    private const TAG = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private FakeRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new FakeRepository(
            heads: ['main' => self::MAIN, 'dev' => self::DEV, 'secret' => self::SECRET],
            ancestors: [self::MAIN => [self::OLDER, str_repeat('1', 40), str_repeat('2', 40), str_repeat('3', 40)], self::DEV => [self::OLDER]],
            files: ['README.md' => 'readme', 'src/app.ts' => 'app', 'src/internal.ts' => 'internal', 'config/secret.php' => 'password'],
            links: ['link-to-config' => 'symlink', 'vendor-module' => 'submodule'],
            commits: [
                ['sha' => str_repeat('3', 40), 'message' => 'Rotate the password in config/secret.php', 'files' => ['config/secret.php']],
                ['sha' => str_repeat('2', 40), 'message' => 'Move the key from config/secret.php', 'files' => ['src/app.ts', 'config/secret.php']],
                ['sha' => str_repeat('1', 40), 'message' => 'Write the readme', 'files' => ['README.md']],
            ],
            tags: ['v1' => self::TAG],
        );
    }

    /** @param array<string, string> $query */
    private function read(array $query, ?BrowseAccess $access, Provider $provider = Provider::GitHub): Preview|BranchChoices
    {
        $metadata = $this->repository->metadata();
        $this->repository->calls = [];
        return (new RepositoryBrowser($this->repository, $provider, static fn(): int => 0))->read($metadata, BrowseQuery::fromQuery($query), $access);
    }

    /** @param array<string, string> $query */
    private function preview(array $query, ?BrowseAccess $access, Provider $provider = Provider::GitHub): Preview
    {
        $preview = $this->read($query, $access, $provider);
        self::assertInstanceOf(Preview::class, $preview);
        return $preview;
    }

    private function branches(?BrowseAccess $access): BranchChoices
    {
        $branches = $this->read(['kind' => 'branches'], $access);
        self::assertInstanceOf(BranchChoices::class, $branches);
        return $branches;
    }

    /** @param Closure(): mixed $read */
    private static function failure(Closure $read): Failure
    {
        try {
            $read();
        } catch (Failure $error) {
            return $error;
        }
        self::fail('The request was allowed.');
    }

    /** @param array<string, string> $query */
    private function denied(array $query, ?BrowseAccess $access, FailureKind $kind, string $code): void
    {
        $error = self::failure(fn(): object => $this->read($query, $access));
        self::assertSame([$kind, $code], [$error->kind, $error->errorCode], json_encode($query, JSON_THROW_ON_ERROR));
        // Rules are checked before any content is read.
        self::assertSame([], array_filter($this->repository->calls, static fn(string $call): bool => preg_match('/^(file|directory|search|history|commit)/', $call) === 1));
    }

    public function testOwnerReadsEveryBranchTagAndCommit(): void
    {
        $branches = $this->branches(null);
        self::assertSame([['main', 'dev', 'secret'], null, 'main', true], [$branches->items, $branches->nextPage, $branches->defaultBranch, $branches->allowHistory]);

        $tag = $this->preview(['ref' => 'v1'], null);
        self::assertSame([self::TAG, 'main', false], [$tag->commitSha, $tag->branch, $tag->historical]);
        self::assertSame(['provider' => 'github', 'id' => 42, 'fullName' => 'owner/app', 'private' => true, 'defaultBranch' => 'main'], $tag->repository);

        // Any commit, even one no branch reaches, without an ancestry check.
        $file = $this->preview(['kind' => 'file', 'path' => 'config/secret.php', 'sha' => self::SECRET], null);
        self::assertSame('password', $file->file['content'] ?? null);
        self::assertSame(['file:config/secret.php'], $this->repository->calls);

        $secret = $this->preview(['branch' => 'secret'], null);
        self::assertSame([self::SECRET, 'secret'], [$secret->commitSha, $secret->branch]);
        $invalid = self::failure(fn(): object => $this->read(['branch' => '../secret'], null));
        self::assertSame([FailureKind::Invalid, 'invalid_branch'], [$invalid->kind, $invalid->errorCode]);
    }

    public function testVisitorSeesOnlyTheBranchesOfTheLink(): void
    {
        $access = new BrowseAccess(['dev', 'main'], true);
        $branches = $this->branches($access);
        self::assertSame([['dev', 'main'], null, 'main', true], [$branches->items, $branches->nextPage, $branches->defaultBranch, $branches->allowHistory]);
        self::assertNotContains('branches', $this->repository->calls);

        $dev = $this->preview(['branch' => 'dev'], $access);
        self::assertSame([self::DEV, 'dev', 'dev'], [$dev->commitSha, $dev->branch, $dev->repository['defaultBranch']]);

        // Without the repository's default branch, a link opens on its first branch.
        $only = new BrowseAccess(['dev'], true);
        self::assertSame('dev', $this->branches($only)->defaultBranch);
        self::assertSame(['dev', 'dev'], [$this->preview([], $only)->branch, $this->preview([], $only)->repository['defaultBranch']]);

        foreach ([['branch' => 'secret'], ['ref' => 'secret'], ['ref' => 'v1'], ['branch' => '../main'], ['ref' => self::SECRET]] as $query) {
            $this->denied($query, $access, FailureKind::Forbidden, 'branch_not_allowed');
        }
    }

    public function testWithoutHistoryAVisitorReadsOnlyTheCurrentHead(): void
    {
        $access = new BrowseAccess(['main'], false);
        self::assertFalse($this->branches($access)->allowHistory);
        foreach ([['kind' => 'history'], ['kind' => 'commit', 'sha' => self::MAIN]] as $query) {
            $this->denied($query, $access, FailureKind::Forbidden, 'history_not_allowed');
        }
        $this->denied(['kind' => 'file', 'path' => 'README.md', 'sha' => self::OLDER], $access, FailureKind::Forbidden, 'revision_not_allowed');
        self::assertNotContains('isAncestor:' . self::OLDER, $this->repository->calls);

        $head = $this->preview(['kind' => 'file', 'path' => 'README.md', 'sha' => self::MAIN], $access);
        self::assertSame([self::MAIN, false, false], [$head->commitSha, $head->historical, $head->allowHistory]);
    }

    public function testWithHistoryAVisitorReadsOnlyCommitsReachableFromTheBranch(): void
    {
        $access = new BrowseAccess(['main'], true);
        $older = $this->preview(['kind' => 'file', 'path' => 'README.md', 'sha' => self::OLDER], $access);
        self::assertSame([self::OLDER, true], [$older->commitSha, $older->historical]);

        // The head of another branch, a tag and an unknown commit are not reachable from main.
        foreach ([self::SECRET, self::TAG, str_repeat('f', 40)] as $sha) {
            $this->denied(['kind' => 'file', 'path' => 'README.md', 'sha' => $sha], $access, FailureKind::Forbidden, 'revision_not_allowed');
        }
        // A branch the link allows does not open commits of another branch.
        $this->denied(['kind' => 'directory', 'branch' => 'main', 'sha' => self::DEV], $access, FailureKind::Forbidden, 'revision_not_allowed');
    }

    public function testHiddenFilesLookExactlyLikeMissingOnes(): void
    {
        $access = new BrowseAccess(['main'], true, new FixedFilePolicy(['main' => ['README.md', 'src/app.ts']]));
        $missing = self::failure(fn(): object => $this->read(['kind' => 'file', 'path' => 'missing.txt'], null));
        foreach ([['kind' => 'file', 'path' => 'config/secret.php'], ['kind' => 'file', 'path' => 'src/internal.ts'], ['kind' => 'directory', 'path' => 'config']] as $query) {
            $hidden = self::failure(fn(): object => $this->read($query, $access));
            self::assertSame([$missing->kind, $missing->errorCode, $missing->getMessage()], [$hidden->kind, $hidden->errorCode, $hidden->getMessage()]);
            self::assertSame([], array_filter($this->repository->calls, static fn(string $call): bool => str_starts_with($call, 'file') || str_starts_with($call, 'directory')));
        }
        $gitlab = self::failure(fn(): object => $this->read(['kind' => 'file', 'path' => 'config/secret.php'], $access, Provider::GitLab));
        self::assertSame(['gitlab_not_found', 'The repository or file is unavailable to this token.'], [$gitlab->errorCode, $gitlab->getMessage()]);

        self::assertSame('app', $this->preview(['kind' => 'file', 'path' => 'src/app.ts'], $access)->file['content'] ?? null);
    }

    public function testFoldersShowOnlyThePathsToVisibleFiles(): void
    {
        $access = new BrowseAccess(['main'], true, new FixedFilePolicy(['main' => ['README.md', 'src/app.ts']]));
        $names = static fn(Preview $preview): array => array_column($preview->entries ?? [], 'name');
        // No hidden folder, symlink or submodule appears; the root is always shown.
        self::assertSame(['README.md', 'src'], $names($this->preview([], $access)));
        self::assertSame(['app.ts'], $names($this->preview(['path' => 'src'], $access)));
        self::assertSame(['README.md', 'config', 'link-to-config', 'src', 'vendor-module'], $names($this->preview([], null)));

        self::assertSame(['README.md', 'src/app.ts'], array_column($this->preview(['kind' => 'search', 'q' => '.'], $access)->search['items'] ?? [], 'path'));
        self::assertCount(4, $this->preview(['kind' => 'search', 'q' => '.'], null)->search['items'] ?? []);
    }

    public function testABranchWithAllFilesSharedIsNotFiltered(): void
    {
        $access = new BrowseAccess(['main', 'dev'], true, new FixedFilePolicy(['main' => ['README.md'], 'dev' => null]));
        self::assertSame('password', $this->preview(['kind' => 'file', 'path' => 'config/secret.php', 'branch' => 'dev'], $access)->file['content'] ?? null);
        $this->denied(['kind' => 'file', 'path' => 'config/secret.php', 'branch' => 'main'], $access, FailureKind::Upstream, 'github_not_found');
    }

    public function testALinkBranchWithoutItsOwnRulesIsDenied(): void
    {
        // Without a configured database any read would throw, so these answers come from the rules alone.
        $policy = new SharePolicy(new Connection(new Settings()), 5, 9, 3, ['main' => ['id' => 1, 'mode' => 'all']]);
        self::assertNull($policy->paths('main'));
        self::assertSame(3, $policy->version());
        foreach (['dev', 'Main', ''] as $branch) {
            $error = self::failure(static fn(): ?array => $policy->paths($branch));
            self::assertSame([FailureKind::Forbidden, 'branch_not_allowed'], [$error->kind, $error->errorCode]);
        }
    }

    public function testSelectedFilesHistoryShowsOnlySharedChangesWithoutMessages(): void
    {
        $access = new BrowseAccess(['main'], true, new FixedFilePolicy(['main' => ['README.md', 'src/app.ts']], 7));
        $history = $this->preview(['kind' => 'history'], $access)->history ?? ['items' => []];
        self::assertSame([str_repeat('2', 40), str_repeat('1', 40)], array_column($history['items'], 'sha'));
        self::assertSame(['Changes to shared files'], array_values(array_unique(array_column($history['items'], 'message'))));
        self::assertSame([''], array_values(array_unique(array_column($history['items'], 'author'))));
        self::assertTrue($history['filtered'] ?? false);

        $detail = $this->preview(['kind' => 'commit', 'sha' => str_repeat('2', 40)], $access)->detail;
        self::assertSame(['src/app.ts'], array_column($detail['files'] ?? [], 'path'));
        self::assertSame(['additions' => 1, 'deletions' => 1, 'total' => 2], $detail['stats'] ?? null);
        self::assertStringNotContainsString('secret', json_encode($detail, JSON_THROW_ON_ERROR));

        // A commit that changes only hidden files looks like a missing one.
        $hidden = self::failure(fn(): object => $this->read(['kind' => 'commit', 'sha' => str_repeat('3', 40)], $access));
        self::assertSame([FailureKind::Upstream, 'github_not_found'], [$hidden->kind, $hidden->errorCode]);
        // The owner sees every commit with its message.
        self::assertSame('Rotate the password in config/secret.php', $this->preview(['kind' => 'history'], null)->history['items'][0]['message'] ?? null);
    }

    public function testLinkBranchesAreAValidatedList(): void
    {
        self::assertSame(['main', 'release/1.0'], BrowseAccess::selected(['main', 'release/1.0']));
        foreach ([[], 'main', ['a' => 'main'], ['main', 'main'], ['main', 7], ['../main'], ['main', 'bad name'], array_map(strval(...), range(1, 51))] as $value) {
            $error = self::failure(static fn(): array => BrowseAccess::selected($value));
            self::assertSame([FailureKind::Invalid, 'invalid_branches'], [$error->kind, $error->errorCode]);
        }
    }

    public function testAFolderIsVisibleOnlyAboveAVisibleFile(): void
    {
        $visible = ['src/app.ts' => true, 'docs/guide/intro.md' => true];
        foreach (['' => true, 'src' => true, 'docs' => true, 'docs/guide' => true, 'sr' => false, 'src/app.ts' => false, 'config' => false, 'doc' => false] as $folder => $shown) {
            self::assertSame($shown, BrowseAccess::folderVisible($visible, (string) $folder), (string) $folder);
        }
    }
}
