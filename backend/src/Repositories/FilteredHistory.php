<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Closure;
use Glimpse\Failure;
use Glimpse\Source\CommitData;
use Glimpse\Source\Provider;
use Glimpse\Source\RepositoryClient;

/**
 * History of a link branch that shares only selected files, on either platform. It lists only commits that change a shared path, never returns
 * commit messages, author names or hidden paths, and computes file pages and totals over shared files only. Finding such
 * commits needs each commit's file list, so one request scans a bounded part of the history and returns a cursor to continue.
 *
 * @phpstan-import-type CommitSummary from RepositoryClient
 * @phpstan-import-type CommitDetail from RepositoryClient
 * @phpstan-type FilteredHistoryPage array{items: list<CommitSummary>, page: int, nextPage: null, limited: bool, filtered: true, cursor: string,
 *   nextCursor: string|null, incomplete: bool, largeCommitsSkipped: bool}
 */
final readonly class FilteredHistory
{
    public const PAGE = 30;
    /** Per request: commit list pages, uncached upstream calls and seconds spent scanning. */
    private const LIST_PAGES = 5;
    private const FETCHES = 40;
    private const SECONDS = 7.0;
    /** File pages (30 files each) read to decide whether a listed commit changes a shared file. */
    private const COMMIT_FILE_PAGES = 10;
    /** File pages read for the shared changes of one commit. */
    private const DETAIL_FILE_PAGES = 20;

    /** @param array<string, true> $visible
     *  @param Closure(): int $fetched Number of upstream calls this request has sent so far.
     */
    public function __construct(
        private RepositoryClient $repository,
        private Provider $provider,
        private array $visible,
        private int $policyVersion,
        private Closure $fetched,
    ) {}

    /** Commits reachable from $sha that change a shared file (under $path when it is a shared folder or file).
     * @return FilteredHistoryPage
     */
    public function history(string $sha, string $path, string $cursor): array
    {
        if ($path !== '' && !isset($this->visible[$path]) && !BrowseAccess::folderVisible($this->visible, $path)) {
            RepositoryBrowser::hidden($this->provider);
        }
        [$page, $offset] = $this->position($cursor);
        // Both platforms list a file path only with commits that changed it, so an exact shared file needs no per-commit check.
        $exact = isset($this->visible[$path]);
        $started = microtime(true);
        $base = ($this->fetched)();
        $items = [];
        $lists = 0;
        $examined = 0;
        $incomplete = false;
        $large = false;
        $limited = false;
        $next = null;
        while (true) {
            if ($lists === self::LIST_PAGES) {
                $incomplete = true;
                $next = [$page, $offset];
                break;
            }
            $list = $this->repository->commitPage($sha, $path, $page);
            $lists++;
            for ($index = $offset; $index < count($list['items']); $index++) {
                if (count($items) === self::PAGE) {
                    $next = [$page, $index];
                    break 2;
                }
                // Each request examines at least one commit, so repeated continuation always advances.
                if ($examined > 0 && (($this->fetched)() - $base >= self::FETCHES || microtime(true) - $started >= self::SECONDS)) {
                    $incomplete = true;
                    $next = [$page, $index];
                    break 2;
                }
                $examined++;
                $commit = $list['items'][$index];
                if ($exact || $this->changesShared($commit['sha'], $path, $large)) {
                    $items[] = CommitData::neutral($commit);
                }
            }
            if (!$list['hasNext']) {
                break;
            }
            if ($page === 1000000) {
                $limited = true;
                break;
            }
            $page++;
            $offset = 0;
            if (count($items) === self::PAGE) {
                $next = [$page, 0];
                break;
            }
        }
        return ['items' => $items, 'page' => 1, 'nextPage' => null, 'limited' => $limited, 'filtered' => true,
            'cursor' => $cursor, 'nextCursor' => $next === null ? null : $next[0] . '.' . $next[1] . '.' . $this->policyVersion,
            'incomplete' => $incomplete, 'largeCommitsSkipped' => $large];
    }

    /** Shared changes of one commit; a commit that changes no shared file looks exactly like a missing one.
     * @return CommitDetail
     */
    public function commit(string $sha, int $page): array
    {
        $shared = [];
        $summary = null;
        $limited = false;
        for ($filesPage = 1; ; $filesPage++) {
            $data = $this->repository->commitFiles($sha, $filesPage);
            $summary ??= $data['commit'];
            foreach ($data['files'] as $file) {
                $shown = CommitData::restrict($file, $this->visible);
                if ($shown !== null) {
                    $shared[] = $shown;
                }
            }
            if (!$data['hasNext']) {
                break;
            }
            if ($filesPage === self::DETAIL_FILE_PAGES) {
                $limited = true;
                break;
            }
        }
        if ($shared === []) {
            RepositoryBrowser::hidden($this->provider);
        }
        $additions = array_sum(array_column($shared, 'additions'));
        $deletions = array_sum(array_column($shared, 'deletions'));
        return ['commit' => CommitData::neutral($summary), 'files' => CommitData::present(array_slice($shared, ($page - 1) * self::PAGE, self::PAGE)),
            'page' => $page, 'nextPage' => count($shared) > $page * self::PAGE ? $page + 1 : null, 'filesLimited' => $limited,
            'stats' => ['additions' => $additions, 'deletions' => $deletions, 'total' => $additions + $deletions]];
    }

    private function changesShared(string $sha, string $scope, bool &$large): bool
    {
        for ($page = 1; $page <= self::COMMIT_FILE_PAGES; $page++) {
            $data = $this->repository->commitFiles($sha, $page);
            foreach ($data['files'] as $file) {
                $shown = CommitData::restrict($file, $this->visible);
                if ($shown !== null && ($scope === '' || $shown['path'] === $scope || str_starts_with($shown['path'], $scope . '/'))) {
                    return true;
                }
            }
            if (!$data['hasNext']) {
                return false;
            }
        }
        // Very large commits are left out rather than listed without a confirmed shared change.
        $large = true;
        return false;
    }

    /** A cursor is only a position in the commit list of the requested revision and rules; it never carries an upstream URL.
     * @return array{int, int}
     */
    private function position(string $cursor): array
    {
        if ($cursor === '') {
            return [1, 0];
        }
        if (preg_match('/^([1-9][0-9]{0,6})\.([0-9]|[12][0-9])\.([1-9][0-9]{0,9})$/D', $cursor, $parts) !== 1 || (int) $parts[1] > 1000000) {
            throw Failure::invalid('invalid_history_request', 'Invalid commit, path, or history page.');
        }
        if ((int) $parts[3] !== $this->policyVersion) {
            throw Failure::conflict('share_policy_changed', 'Access through this link has changed. Reload to see what it allows now.');
        }
        return [(int) $parts[1], (int) $parts[2]];
    }
}
