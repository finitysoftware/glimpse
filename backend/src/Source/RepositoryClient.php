<?php

declare(strict_types=1);

namespace Glimpse\Source;

/**
 * One repository on one platform, read through its owner's connection. GitHub and GitLab return the same shapes.
 *
 * @phpstan-type CatalogueItem array{id: int, fullName: string, owner: string, name: string, private: bool, visibility?: string, defaultBranch: string, description: string}
 * @phpstan-type Metadata array{id: int, fullName: string, private: bool, defaultBranch: string, owner?: string, name?: string, visibility?: string}
 * @phpstan-type Entry array{name: string, path: string, type: string, size: int|null}
 * @phpstan-type Listing array{entries: list<Entry>, limited: bool}
 * @phpstan-type SearchPage array{items: list<Entry>, page: int, nextPage: int|null, limited: bool}
 * @phpstan-type FileContent array{path: string, commitSha: string, bytes: int, content: string}
 * @phpstan-type CommitSummary array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>}
 * @phpstan-type CommitHistory array{items: list<CommitSummary>, page: int, nextPage: int|null, limited: bool}
 * @phpstan-type ShownFile array{path: string, previousPath: string|null, status: string, additions: int, deletions: int, patch: string|null, patchState: string}
 * @phpstan-type CommitStats array{additions: int, deletions: int, total: int}
 * @phpstan-type CommitDetail array{commit: CommitSummary, files: list<ShownFile>, page: int, nextPage: int|null, filesLimited: bool, stats: CommitStats}
 */
interface RepositoryClient
{
    /** @return Metadata */
    public function metadata(): array;

    /** @return array{items: list<string>, nextPage: int|null} */
    public function branches(int $page): array;

    public function branchHead(string $branch): string;

    public function resolveCommit(string $ref): string;

    public function isAncestor(string $sha, string $head): bool;

    /** @return Listing */
    public function directory(string $path, string $sha): array;

    /** Regular files of a commit keyed by exact path; sizes are NULL where the platform does not report them.
     * @return array{files: array<array-key, int|null>, truncated: bool}
     */
    public function files(string $sha): array;

    /** @param array<string, true>|null $visible
     *  @return SearchPage
     */
    public function search(string $sha, string $query, int $page, ?array $visible): array;

    /** @return CommitHistory */
    public function history(string $sha, string $path, int $page): array;

    /** @return array{items: list<CommitSummary>, hasNext: bool} */
    public function commitPage(string $sha, string $path, int $page): array;

    /** @return CommitDetail */
    public function commit(string $sha, int $page): array;

    /** @return array{commit: CommitSummary,
     *   files: list<array{path: string, previousPath: string|null, status: string, additions: int, deletions: int, patch: string|null, limited?: bool}>,
     *   stats: CommitStats, hasNext: bool}
     */
    public function commitFiles(string $sha, int $page): array;

    /** @return FileContent */
    public function file(string $path, string $sha): array;
}
