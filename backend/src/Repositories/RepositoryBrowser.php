<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Closure;
use Glimpse\Failure;
use Glimpse\Source\BranchName;
use Glimpse\Source\Provider;
use Glimpse\Source\RepositoryClient;

/**
 * One preview of a repository, read through its client within what the viewer may see: the owner sees everything, a link
 * visitor only the link's branches, History and files. It never reads the database or the cache, so its rules are checked
 * before any content is read and can be tested on their own.
 *
 * @phpstan-import-type Metadata from RepositoryClient
 */
final readonly class RepositoryBrowser
{
    /** @param Closure(): int $fetched Number of upstream calls this request has sent so far. */
    public function __construct(private RepositoryClient $repository, private Provider $provider, private Closure $fetched) {}

    /**
     * @param Metadata $metadata
     */
    public function read(array $metadata, BrowseQuery $query, ?BrowseAccess $access): Preview|BranchChoices
    {
        $kind = $query->kind;
        $path = $query->path;
        $page = $query->page;
        $repo = $this->repository;
        $allowHistory = $access === null || $access->history;
        if (!$allowHistory && in_array($kind, ['history', 'commit'], true)) {
            throw Failure::forbidden('history_not_allowed', 'History is not available through this access link.');
        }
        if ($kind === 'branches') {
            if ($access !== null) {
                return new BranchChoices($access->branches, null, $access->branch('', $metadata['defaultBranch']), $allowHistory);
            }
            $choices = $repo->branches($page);
            return new BranchChoices($choices['items'], $choices['nextPage'], $metadata['defaultBranch'], $allowHistory);
        }
        [$selected, $commit, $head] = $access === null ? $this->ownerRevision($metadata, $query) : $this->linkRevision($metadata, $query, $access);
        if ($access !== null) {
            // A link opens on one of its own branches, never on a default branch it does not share.
            $metadata['defaultBranch'] = $selected;
        }
        // Selected-files links: hidden paths look exactly like missing ones, and no hidden name, size or count leaves the server.
        // The same rules apply at every reachable revision, so earlier versions of a shared path are shared too.
        $visible = $access?->policy?->paths($selected);
        $filtered = null;
        if ($visible !== null) {
            if (($kind === 'file' && !isset($visible[$path])) || ($kind === 'directory' && !BrowseAccess::folderVisible($visible, $path))) {
                self::hidden($this->provider);
            }
            $filtered = new FilteredHistory($repo, $this->provider, $visible, $access->policy->version(), $this->fetched);
        }
        $preview = new Preview(['provider' => $this->provider->value] + $metadata, $commit, $path, $kind, $selected, $allowHistory, $commit !== $head);
        return match ($kind) {
            'file' => $preview->withContent(file: $repo->file($path, $commit)),
            'history' => $preview->withContent(history: $filtered?->history($commit, $path, $query->cursor) ?? $repo->history($commit, $path, $page)),
            'commit' => $preview->withContent(detail: $filtered?->commit($commit, $page) ?? $repo->commit($commit, $page)),
            'search' => $preview->withContent(search: $repo->search($commit, $query->search, $page, $visible)),
            default => $preview->withContent(listing: self::visibleEntries($repo->directory($path, $commit), $visible)),
        };
    }

    /** The owner reads any branch, tag or commit; without a branch, the commit comes from the ref or the default branch.
     * @param array{defaultBranch: string} $metadata
     * @return array{string, string, string} The branch, the commit and the head the commit is compared with.
     */
    private function ownerRevision(array $metadata, BrowseQuery $query): array
    {
        if ($query->branch === '') {
            $commit = $query->sha !== '' ? $query->sha : $this->repository->resolveCommit($query->ref !== '' ? $query->ref : $metadata['defaultBranch']);
            return [$metadata['defaultBranch'], $commit, $commit];
        }
        if (!BranchName::valid($query->branch)) {
            throw Failure::invalid('invalid_branch', 'Invalid branch name.');
        }
        $head = $this->repository->branchHead($query->branch);
        return [$query->branch, $query->sha !== '' ? $query->sha : $head, $head];
    }

    /** A link visitor reads only an exact branch of the link (never a tag or another ref), at its current head or, with History,
     * at a commit reachable from it. Reachability is checked before any content or cache read.
     * @param array{defaultBranch: string} $metadata
     * @return array{string, string, string} The branch, the commit and the branch head.
     */
    private function linkRevision(array $metadata, BrowseQuery $query, BrowseAccess $access): array
    {
        $branch = $access->branch($query->branch !== '' ? $query->branch : $query->ref, $metadata['defaultBranch']);
        $head = $this->repository->branchHead($branch);
        $commit = $query->sha !== '' ? $query->sha : $head;
        if ($commit !== $head && (!$access->history || !$this->repository->isAncestor($commit, $head))) {
            throw Failure::forbidden('revision_not_allowed', 'This revision is not available through the selected branch. Refresh to open its current files.');
        }
        return [$branch, $commit, $head];
    }

    /** A hidden path looks exactly like a missing one on the same platform. */
    public static function hidden(Provider $provider): never
    {
        throw Failure::upstream($provider->code('not_found'), $provider === Provider::GitHub
            ? 'The repository or file is unavailable to this installation.' : 'The repository or file is unavailable to this token.');
    }

    /** @param array{entries: list<array{name: string, path: string, type: string, size: int|null}>, limited: bool} $listing
     *  @param array<string, true>|null $visible
     *  @return array{entries: list<array{name: string, path: string, type: string, size: int|null}>, limited: bool}
     */
    private static function visibleEntries(array $listing, ?array $visible): array
    {
        if ($visible === null) {
            return $listing;
        }
        // Folders appear only as the structure leading to visible files; symlinks and submodules never appear.
        $listing['entries'] = array_values(array_filter($listing['entries'], static fn(array $entry): bool =>
            ($entry['type'] === 'file' && isset($visible[$entry['path']])) || ($entry['type'] === 'dir' && BrowseAccess::folderVisible($visible, $entry['path']))));
        return $listing;
    }
}
