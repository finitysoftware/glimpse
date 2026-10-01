<?php

declare(strict_types=1);

namespace Glimpse\Source;

use Glimpse\Failure;

/**
 * Validate upstream commit data and expose only bounded, renderable fields.
 *
 * @phpstan-import-type CommitDetail from RepositoryClient
 */
final class CommitData
{
    public static function validateQuery(string $sha, string $path, int $page, int $maxPage): void
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $sha) || $page < 1 || $page > $maxPage || !self::validPath($path, true)) {
            throw Failure::invalid('invalid_history_request', 'Invalid commit, path, or history page.');
        }
    }

    /** @return array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>} */
    public static function summary(mixed $data): array
    {
        if (!is_array($data) || !is_string($data['sha'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $data['sha'])
            || !is_array($data['commit'] ?? null) || !is_string($data['commit']['message'] ?? null)
            || !is_array($data['parents'] ?? null) || !array_is_list($data['parents']) || count($data['parents']) > 64) {
            self::invalid();
        }
        $parents = [];
        foreach ($data['parents'] as $parent) {
            if (!is_array($parent) || !is_string($parent['sha'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $parent['sha'])) {
                self::invalid();
            }
            $parents[] = $parent['sha'];
        }
        $author = is_array($data['commit']['author'] ?? null) ? $data['commit']['author'] : [];
        $date = $author['date'] ?? null;
        if ($date !== null && (!is_string($date) || strlen($date) > 40 || strtotime($date) === false)) {
            self::invalid();
        }
        return ['sha' => $data['sha'], 'message' => mb_strcut($data['commit']['message'], 0, 16384, 'UTF-8'),
            'messageLimited' => strlen($data['commit']['message']) > 16384,
            'author' => is_string($author['name'] ?? null) ? mb_substr($author['name'], 0, 255) : 'Unknown author',
            'date' => $date, 'parents' => $parents];
    }

    /** @param array<array-key, mixed> $data
     * @return CommitDetail
     */
    public static function detail(array $data, string $sha, int $page, bool $hasNext): array
    {
        $parsed = self::changedFiles($data, $sha);
        // GitHub's file pagination stops at 3,000. Equality is conservatively marked too.
        return ['commit' => $parsed['commit'], 'files' => self::present($parsed['files']), 'page' => $page,
            'nextPage' => $hasNext && $page < 100 ? $page + 1 : null,
            'filesLimited' => $page === 100 && (count($parsed['files']) === 30 || $hasNext),
            'stats' => $parsed['stats']];
    }

    /** One validated page of a commit's changed files, before any display limit.
     * @param array<array-key, mixed> $data
     * @return array{commit: array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>},
     *   files: list<array{path: string, previousPath: string|null, status: string, additions: int, deletions: int, patch: string|null}>,
     *   stats: array{additions: int, deletions: int, total: int}}
     */
    public static function changedFiles(array $data, string $sha): array
    {
        $summary = self::summary($data);
        if ($summary['sha'] !== $sha || !is_array($data['files'] ?? null) || !array_is_list($data['files']) || count($data['files']) > 30) {
            self::invalid();
        }
        $stats = $data['stats'] ?? null;
        if (!is_array($stats)) {
            self::invalid();
        }
        $totals = [];
        foreach (['additions', 'deletions', 'total'] as $field) {
            $totals[$field] = self::nonnegative($stats[$field] ?? null);
        }
        $files = [];
        foreach ($data['files'] as $file) {
            if (!is_array($file) || !is_string($file['filename'] ?? null) || !self::validPath($file['filename'])
                || !in_array($file['status'] ?? null, ['added', 'removed', 'modified', 'renamed', 'copied', 'changed', 'unchanged'], true)) {
                self::invalid();
            }
            $previous = $file['previous_filename'] ?? null;
            if ($previous !== null && (!is_string($previous) || !self::validPath($previous))) {
                self::invalid();
            }
            $patch = $file['patch'] ?? null;
            if ($patch !== null && !is_string($patch)) {
                self::invalid();
            }
            $files[] = ['path' => $file['filename'], 'previousPath' => $previous, 'status' => $file['status'],
                'additions' => self::nonnegative($file['additions'] ?? null), 'deletions' => self::nonnegative($file['deletions'] ?? null), 'patch' => $patch];
        }
        return ['commit' => $summary, 'files' => $files, 'stats' => $totals];
    }

    /** Applies the shared byte and line budget of one response to its patches. `limited` marks a patch the platform omitted as too large.
     * @param list<array{path: string, previousPath: string|null, status: string, additions: int, deletions: int, patch: string|null, restricted?: bool, limited?: bool}> $files
     * @return list<array{path: string, previousPath: string|null, status: string, additions: int, deletions: int, patch: string|null, patchState: string}>
     */
    public static function present(array $files): array
    {
        $result = [];
        $bytesLeft = 1048576;
        $linesLeft = 20000;
        foreach ($files as $file) {
            $patch = $file['patch'];
            $state = ($file['restricted'] ?? false) ? 'restricted' : (($file['limited'] ?? false) ? 'limited' : 'unavailable');
            if ($patch !== null) {
                $lines = substr_count($patch, "\n") + 1;
                if (strlen($patch) > $bytesLeft || $lines > $linesLeft) {
                    $patch = null;
                    $state = 'limited';
                } else {
                    $bytesLeft -= strlen($patch);
                    $linesLeft -= $lines;
                    $state = self::completePatch($patch, $file['additions'], $file['deletions']) ? 'complete' : 'incomplete';
                }
            }
            $result[] = ['path' => $file['path'], 'previousPath' => $file['previousPath'], 'status' => $file['status'],
                'additions' => $file['additions'], 'deletions' => $file['deletions'], 'patch' => $patch, 'patchState' => $state];
        }
        return $result;
    }

    /** What a link that shares only $visible paths may show of one changed file, or NULL when the change is entirely hidden.
     * A rename or copy with one hidden side keeps only the shared path, without the other name, the patch or line counts:
     * it appears as added (moved in) or removed (moved away).
     * @param array{path: string, previousPath: string|null, status: string, additions: int, deletions: int, patch: string|null, limited?: bool} $file
     * @param array<string, true> $visible
     * @return array{path: string, previousPath: string|null, status: string, additions: int, deletions: int, patch: string|null, restricted?: bool, limited?: bool}|null
     */
    public static function restrict(array $file, array $visible): ?array
    {
        $current = isset($visible[$file['path']]);
        if ($file['previousPath'] === null) {
            return $current ? $file : null;
        }
        $previous = isset($visible[$file['previousPath']]);
        if ($current && $previous) {
            return $file;
        }
        if (!$current && !$previous) {
            return null;
        }
        // A copy leaves its source in place, so a hidden copy target changes nothing that is shared.
        if (!$current && $file['status'] === 'copied') {
            return null;
        }
        return ['path' => $current ? $file['path'] : $file['previousPath'], 'previousPath' => null, 'status' => $current ? 'added' : 'removed',
            'additions' => 0, 'deletions' => 0, 'patch' => null, 'restricted' => true];
    }

    /** Commit messages and author names are free text that may name hidden files; restricted links get a neutral summary.
     * @param array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>} $summary
     * @return array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>}
     */
    public static function neutral(array $summary): array
    {
        return ['sha' => $summary['sha'], 'message' => 'Changes to shared files', 'messageLimited' => false, 'author' => '',
            'date' => $summary['date'], 'parents' => $summary['parents']];
    }

    private static function completePatch(string $patch, int $additions, int $deletions): bool
    {
        $added = 0;
        $removed = 0;
        $old = 0;
        $new = 0;
        $hunk = false;
        foreach (explode("\n", rtrim($patch, "\n")) as $line) {
            if (preg_match('/^@@ -\d+(?:,(\d+))? \+\d+(?:,(\d+))? @@/', $line, $matches)) {
                if ($old !== 0 || $new !== 0) {
                    return false;
                }
                $old = ($matches[1] ?? '') !== '' ? (int) $matches[1] : 1;
                $new = ($matches[2] ?? '') !== '' ? (int) $matches[2] : 1;
                $hunk = true;
            } elseif (str_starts_with($line, '\\ No newline at end of file')) {
                continue;
            } elseif (!$hunk) {
                return false;
            } elseif (str_starts_with($line, '+')) {
                $added++;
                $new--;
            } elseif (str_starts_with($line, '-')) {
                $removed++;
                $old--;
            } elseif (str_starts_with($line, ' ')) {
                $old--;
                $new--;
            } else {
                return false;
            }
            if ($old < 0 || $new < 0) {
                return false;
            }
        }
        return $hunk && $old === 0 && $new === 0 && $added === $additions && $removed === $deletions;
    }

    private static function validPath(string $path, bool $allowRoot = false): bool
    {
        return ($path === '' && $allowRoot) || ($path !== '' && strlen($path) <= 1024
            && !preg_match('/[\x00-\x1f\x7f\\\\]/', $path) && array_intersect(['', '.', '..'], explode('/', $path)) === []);
    }

    private static function nonnegative(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            self::invalid();
        }
        return $value;
    }

    private static function invalid(): never
    {
        throw Failure::upstream('github_invalid_response', 'GitHub returned an unexpected commit response.');
    }
}
