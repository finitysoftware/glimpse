<?php

declare(strict_types=1);

namespace Glimpse\GitLab;

use Glimpse\Failure;
use Glimpse\Source\BranchName;
use Glimpse\Source\CommitData;
use Glimpse\Source\DerivedCache;
use Glimpse\Source\HttpResult;
use Glimpse\Source\HttpsGet;
use Glimpse\Source\RateLimit;
use Glimpse\Source\RepositoryClient;
use Glimpse\Source\Transport;
use JsonException;

/** GitLab REST API v4 for one connection. Projects are addressed by their stable numeric ID; results have the same shapes as
 * GitHubClient's, so repository views, visibility rules and access links work the same on both platforms.
 *
 * @phpstan-import-type CommitHistory from RepositoryClient
 * @phpstan-import-type CommitDetail from RepositoryClient
 */
final class GitLabClient
{
    /** Pages (100 entries each) and seconds one request may spend assembling the file manifest of a commit. */
    public const TREE_PAGES = 50;
    public const TREE_SECONDS = 8.0;
    /** Pages (100 entries each) of one folder listing. */
    private const FOLDER_PAGES = 10;
    private const SEGMENT = '[A-Za-z0-9_.][A-Za-z0-9_.-]{0,254}';

    private readonly float $deadline;
    private bool $hasNextPage = false;

    public function __construct(private readonly Transport $transport, private readonly ?DerivedCache $cache = null)
    {
        $this->deadline = microtime(true) + 15;
    }

    /** Identity of the token's GitLab account; used to verify a token before it is saved.
     * @return array{id: int, username: string}
     */
    public function user(): array
    {
        $data = $this->request('/user');
        if (!is_int($data['id'] ?? null) || $data['id'] < 1 || !is_string($data['username'] ?? null)
            || preg_match('/^' . self::SEGMENT . '$/D', $data['username']) !== 1) {
            $this->invalidResponse();
        }
        return ['id' => $data['id'], 'username' => $data['username']];
    }

    /** Scopes and expiry of the token itself, or NULL when the instance does not report them (older GitLab, other token types).
     * @return array{scopes: list<string>, expiresAt: string|null, active: bool}|null
     */
    public function tokenInfo(): ?array
    {
        $result = $this->fetch('/personal_access_tokens/self');
        if (in_array($result->status, [403, 404], true)) {
            return null;
        }
        $data = $this->decode($result, false);
        $scopes = $data['scopes'] ?? null;
        $expires = $data['expires_at'] ?? null;
        if (!is_array($scopes) || !array_is_list($scopes) || count($scopes) > 50
            || ($expires !== null && (!is_string($expires) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expires) !== 1))) {
            $this->invalidResponse();
        }
        $names = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || preg_match('/^[a-z_]{1,64}$/D', $scope) !== 1) {
                $this->invalidResponse();
            }
            $names[] = $scope;
        }
        return ['scopes' => $names, 'expiresAt' => $expires, 'active' => ($data['active'] ?? true) === true && ($data['revoked'] ?? false) !== true];
    }

    /** Projects the account can read code of (Reporter or higher), 30 per page.
     * @return array{items: list<array{id: int, fullName: string, owner: string, name: string, private: bool, visibility: string, defaultBranch: string, description: string}>, nextPage: int|null}
     */
    public function projects(int $page): array
    {
        if ($page < 1 || $page > 10000) {
            throw Failure::invalid('invalid_page', 'Invalid repository page.');
        }
        $data = $this->request('/projects?membership=true&min_access_level=20&order_by=path&sort=asc&per_page=30&page=' . $page, true);
        if (count($data) > 30) {
            $this->invalidResponse();
        }
        $items = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                $this->invalidResponse();
            }
            $project = $this->project($item);
            // Namespaces deeper or longer than the local columns allow are left out instead of failing the whole page.
            if ($project === null) {
                continue;
            }
            $items[] = $project + ['description' => is_string($item['description'] ?? null) ? mb_substr($item['description'], 0, 500) : ''];
        }
        return ['items' => $items, 'nextPage' => $this->hasNextPage ? $page + 1 : null];
    }

    /** @return array{id: int, fullName: string, owner: string, name: string, private: bool, visibility: string, defaultBranch: string} */
    public function metadata(int $project): array
    {
        $data = $this->request(self::projectPath($project));
        $metadata = $this->project($data);
        if ($metadata === null || $metadata['id'] !== $project) {
            $this->invalidResponse();
        }
        if (($data['empty_repo'] ?? false) === true || $metadata['defaultBranch'] === '') {
            throw Failure::upstream('gitlab_empty_repository', 'This repository has no available commit.');
        }
        return $metadata;
    }

    public function resolveCommit(int $project, string $ref): string
    {
        if ($ref === '' || strlen($ref) > 255 || preg_match('/[\x00-\x20\x7f]/', $ref)) {
            throw Failure::invalid('invalid_ref', 'Invalid repository revision.');
        }
        return $this->sha($this->request(self::projectPath($project) . '/repository/commits/' . rawurlencode($ref))['id'] ?? null);
    }

    /** @return array{items: list<string>, nextPage: int|null} */
    public function branches(int $project, int $page): array
    {
        if ($page < 1 || $page > 10000) {
            throw Failure::invalid('invalid_page', 'Invalid branch page.');
        }
        $data = $this->request(self::projectPath($project) . '/repository/branches?per_page=100&page=' . $page, true);
        if (count($data) > 100) {
            $this->invalidResponse();
        }
        $items = [];
        foreach ($data as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || !BranchName::valid($item['name'])) {
                $this->invalidResponse();
            }
            $items[] = $item['name'];
        }
        return ['items' => $items, 'nextPage' => $this->hasNextPage && $page < 10000 ? $page + 1 : null];
    }

    public function branchHead(int $project, string $branch): string
    {
        if (!BranchName::valid($branch)) {
            throw Failure::invalid('invalid_branch', 'Invalid branch name.');
        }
        $data = $this->request(self::projectPath($project) . '/repository/branches/' . rawurlencode($branch));
        if (($data['name'] ?? null) !== $branch || !is_array($data['commit'] ?? null)) {
            $this->invalidResponse();
        }
        return $this->sha($data['commit']['id'] ?? null);
    }

    /** True when $sha is $head or one of its ancestors: their merge base is $sha itself. Unrelated histories have no merge base. */
    public function isAncestor(int $project, string $sha, string $head): bool
    {
        CommitData::validateQuery($sha, '', 1, 1);
        CommitData::validateQuery($head, '', 1, 1);
        if ($sha === $head) {
            return true;
        }
        $result = $this->fetch(self::projectPath($project) . '/repository/merge_base?refs%5B%5D=' . $sha . '&refs%5B%5D=' . $head);
        if (in_array($result->status, [400, 404], true)) {
            return false;
        }
        return $this->sha($this->decode($result, false)['id'] ?? null) === $sha;
    }

    /** @return array{entries: list<array{name: string, path: string, type: string, size: int|null}>, limited: bool} */
    public function directory(int $project, string $path, string $sha): array
    {
        $segments = $path === '' ? [] : explode('/', $path);
        if (strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)
            || array_intersect(['', '.', '..'], $segments) !== [] || !preg_match('/^[a-f0-9]{40}$/D', $sha)) {
            throw Failure::invalid('invalid_path', 'Invalid folder path or commit SHA.');
        }
        $entries = [];
        $limited = false;
        for ($page = 1; ; $page++) {
            $query = ($path === '' ? [] : ['path' => $path]) + ['ref' => $sha, 'per_page' => 100, 'page' => $page];
            $data = $this->request(self::projectPath($project) . '/repository/tree?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), true);
            if (count($data) > 100) {
                $this->invalidResponse();
            }
            foreach ($data as $item) {
                $entry = $this->treeEntry($item);
                if ($entry['path'] !== ($path === '' ? '' : $path . '/') . $entry['name']) {
                    $this->invalidResponse();
                }
                $entries[] = ['name' => $entry['name'], 'path' => $entry['path'], 'type' => $entry['type'], 'size' => null];
            }
            if (!$this->hasNextPage) {
                break;
            }
            if ($page === self::FOLDER_PAGES) {
                $limited = true;
                break;
            }
        }
        usort($entries, static fn(array $a, array $b): int => (($b['type'] === 'dir') <=> ($a['type'] === 'dir')) ?: strnatcasecmp($a['name'], $b['name']));
        return ['entries' => $entries, 'limited' => $limited];
    }

    /** Regular files of a commit from the recursive tree, without downloading content or sizes. Symlinks and submodules are excluded.
     * A complete manifest (or one cut at the page limit) is cached as one entry; one cut by the time budget is not.
     * @return array{files: array<string, int|null>, truncated: bool} Keys are exact, case-sensitive paths.
     */
    public function files(int $project, string $sha): array
    {
        CommitData::validateQuery($sha, '', 1, 1);
        $name = 'gitlab-tree-v1:' . $project . ':' . $sha;
        $cached = $this->cache?->derived($name);
        if ($cached !== null) {
            try {
                $data = json_decode($cached, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($data) && is_array($data['files'] ?? null) && array_is_list($data['files']) && is_bool($data['truncated'] ?? null)) {
                    $files = [];
                    foreach ($data['files'] as $path) {
                        if (is_string($path)) {
                            $files[$path] = null;
                        }
                    }
                    return ['files' => $files, 'truncated' => $data['truncated']];
                }
            } catch (JsonException) {
            }
        }
        $files = [];
        $truncated = false;
        $timedOut = false;
        $started = microtime(true);
        for ($page = 1; ; $page++) {
            $data = $this->request(self::projectPath($project) . '/repository/tree?ref=' . $sha . '&recursive=true&per_page=100&page=' . $page, true);
            if (count($data) > 100) {
                $this->invalidResponse();
            }
            foreach ($data as $item) {
                $entry = $this->treeEntry($item);
                if (array_key_exists($entry['path'], $files)) {
                    $this->invalidResponse();
                }
                if ($entry['type'] === 'file') {
                    $files[$entry['path']] = null;
                }
            }
            if (!$this->hasNextPage) {
                break;
            }
            if ($page === self::TREE_PAGES) {
                $truncated = true;
                break;
            }
            if (microtime(true) - $started >= self::TREE_SECONDS) {
                $truncated = $timedOut = true;
                break;
            }
        }
        if (!$timedOut) {
            $this->cache?->storeDerived($name, json_encode(
                ['files' => array_map('strval', array_keys($files)), 'truncated' => $truncated],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ), 3600);
        }
        return ['files' => $files, 'truncated' => $truncated];
    }

    /** @return CommitHistory */
    public function history(int $project, string $sha, string $path, int $page): array
    {
        $list = $this->commitPage($project, $sha, $path, $page);
        return ['items' => $list['items'], 'page' => $page, 'nextPage' => $list['hasNext'] && $page < 1000000 ? $page + 1 : null,
            'limited' => $list['hasNext'] && $page === 1000000];
    }

    /** One page of 30 commits reachable from $sha, optionally only those GitLab lists for $path.
     * @return array{items: list<array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>}>, hasNext: bool}
     */
    public function commitPage(int $project, string $sha, string $path, int $page): array
    {
        CommitData::validateQuery($sha, $path, $page, 1000000);
        $query = ['ref_name' => $sha] + ($path === '' ? [] : ['path' => $path]) + ['per_page' => 30, 'page' => $page];
        $data = $this->request(self::projectPath($project) . '/repository/commits?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), true);
        if (count($data) > 30) {
            $this->invalidResponse();
        }
        return ['items' => array_values(array_map(fn(mixed $item): array => $this->summary($item), $data)), 'hasNext' => $this->hasNextPage];
    }

    /** @return CommitDetail */
    public function commit(int $project, string $sha, int $page): array
    {
        $changes = $this->changes($project, $sha, $page);
        return ['commit' => $changes['commit'], 'files' => CommitData::present($changes['files']), 'page' => $page,
            'nextPage' => $changes['hasNext'] && $page < 100 ? $page + 1 : null,
            'filesLimited' => $page === 100 && (count($changes['files']) === 30 || $changes['hasNext']), 'stats' => $changes['stats']];
    }

    /** One page of a commit's changed files; same shape as GitHubClient::commitFiles().
     * @return array{commit: array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>},
     *   files: list<array{path: string, previousPath: string|null, status: string, additions: int, deletions: int, patch: string|null, limited?: bool}>,
     *   stats: array{additions: int, deletions: int, total: int}, hasNext: bool}
     */
    public function commitFiles(int $project, string $sha, int $page): array
    {
        $changes = $this->changes($project, $sha, $page);
        return ['hasNext' => $changes['hasNext'] && $page < 100] + $changes;
    }

    /** @return array{path: string, commitSha: string, bytes: int, content: string} */
    public function file(int $project, string $path, string $sha): array
    {
        $segments = explode('/', $path);
        if ($path === '' || strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)
            || array_intersect(['', '.', '..'], $segments) !== [] || preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw Failure::invalid('invalid_path', 'Invalid file path or commit SHA.');
        }
        $data = $this->request(self::projectPath($project) . '/repository/files/' . rawurlencode($path) . '?ref=' . $sha);
        if (($data['encoding'] ?? '') !== 'base64' || !is_int($data['size'] ?? null) || !is_string($data['content'] ?? null)
            || ($data['file_path'] ?? null) !== $path) {
            throw Failure::rejected('file_unavailable', 'This item cannot be displayed as a regular text file.');
        }
        if ($data['size'] > 1048576 || strlen($data['content']) > 1500000) {
            throw Failure::rejected('file_too_large', 'This file exceeds the text preview limit.');
        }
        $content = base64_decode($data['content'], true);
        if ($content === false || strlen($content) !== $data['size']) {
            $this->invalidResponse();
        }
        if (substr_count($content, "\n") + 1 > 20000) {
            throw Failure::rejected('file_too_large', 'This file exceeds the text preview limit.');
        }
        if (str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8')) {
            throw Failure::rejected('binary_file', 'Binary files cannot be displayed as text.');
        }
        return ['path' => $path, 'commitSha' => $sha, 'bytes' => strlen($content), 'content' => $content];
    }

    /** Commit summary and statistics, plus one page of 30 changed files with patches normalized to GitHub's patch form.
     * @return array{commit: array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>},
     *   files: list<array{path: string, previousPath: string|null, status: string, additions: int, deletions: int, patch: string|null, limited?: bool}>,
     *   stats: array{additions: int, deletions: int, total: int}, hasNext: bool}
     */
    private function changes(int $project, string $sha, int $page): array
    {
        CommitData::validateQuery($sha, '', $page, 100);
        $detail = $this->request(self::projectPath($project) . '/repository/commits/' . $sha . '?stats=true');
        $summary = $this->summary($detail);
        $stats = $detail['stats'] ?? null;
        if ($summary['sha'] !== $sha || !is_array($stats)) {
            $this->invalidResponse();
        }
        $totals = [];
        foreach (['additions', 'deletions', 'total'] as $field) {
            if (!is_int($stats[$field] ?? null) || $stats[$field] < 0) {
                $this->invalidResponse();
            }
            $totals[$field] = $stats[$field];
        }
        $data = $this->request(self::projectPath($project) . '/repository/commits/' . $sha . '/diff?per_page=30&page=' . $page, true);
        $hasNext = $this->hasNextPage;
        if (count($data) > 30) {
            $this->invalidResponse();
        }
        $files = [];
        foreach ($data as $item) {
            $files[] = $this->changedFile($item);
        }
        return ['commit' => $summary, 'files' => $files, 'stats' => $totals, 'hasNext' => $hasNext];
    }

    /** @return array{path: string, previousPath: string|null, status: string, additions: int, deletions: int, patch: string|null, limited?: bool} */
    private function changedFile(mixed $item): array
    {
        if (!is_array($item) || !is_string($item['new_path'] ?? null) || !is_string($item['old_path'] ?? null)
            || !self::validPath($item['new_path']) || !self::validPath($item['old_path'])) {
            $this->invalidResponse();
        }
        foreach (['new_file', 'renamed_file', 'deleted_file'] as $flag) {
            if (!is_bool($item[$flag] ?? false)) {
                $this->invalidResponse();
            }
        }
        $diff = $item['diff'] ?? '';
        if (!is_string($diff)) {
            $this->invalidResponse();
        }
        $status = match (true) {
            ($item['deleted_file'] ?? false) === true => 'removed',
            ($item['new_file'] ?? false) === true => 'added',
            ($item['renamed_file'] ?? false) === true => 'renamed',
            default => 'modified',
        };
        $limited = ($item['too_large'] ?? false) === true || ($item['collapsed'] ?? false) === true;
        $patch = null;
        $additions = 0;
        $deletions = 0;
        // GitLab sends only the hunks (optionally after ---/+++ headers); binary changes and omitted diffs have no hunk.
        $start = $limited ? false : strpos("\n" . $diff, "\n@@ ");
        if ($start !== false) {
            $patch = substr($diff, $start);
            $hunk = false;
            foreach (explode("\n", $patch) as $line) {
                if (str_starts_with($line, '@@ ')) {
                    $hunk = true;
                } elseif ($hunk && str_starts_with($line, '+')) {
                    $additions++;
                } elseif ($hunk && str_starts_with($line, '-')) {
                    $deletions++;
                }
            }
        }
        $file = ['path' => $status === 'removed' ? $item['old_path'] : $item['new_path'], 'previousPath' => $status === 'renamed' ? $item['old_path'] : null,
            'status' => $status, 'additions' => $additions, 'deletions' => $deletions, 'patch' => $patch];
        return $limited ? $file + ['limited' => true] : $file;
    }

    /** @param array<array-key, mixed> $item
     *  @return array{id: int, fullName: string, owner: string, name: string, private: bool, visibility: string, defaultBranch: string}|null
     */
    private function project(array $item): ?array
    {
        $full = $item['path_with_namespace'] ?? null;
        $name = $item['path'] ?? null;
        $branch = $item['default_branch'] ?? null;
        if (!is_int($item['id'] ?? null) || $item['id'] < 1 || !is_string($full) || !is_string($name)
            || !in_array($item['visibility'] ?? null, ['private', 'internal', 'public'], true)
            || ($branch !== null && (!is_string($branch) || ($branch !== '' && !BranchName::valid($branch))))
            || !str_ends_with($full, '/' . $name) || preg_match('#^' . self::SEGMENT . '(?:/' . self::SEGMENT . ')+$#D', $full) !== 1) {
            $this->invalidResponse();
        }
        $owner = substr($full, 0, -strlen($name) - 1);
        foreach (explode('/', $full) as $segment) {
            if (in_array($segment, ['.', '..'], true)) {
                $this->invalidResponse();
            }
        }
        if (strlen($owner) > 255 || strlen($name) > 255) {
            return null;
        }
        return ['id' => $item['id'], 'fullName' => $full, 'owner' => $owner, 'name' => $name, 'private' => $item['visibility'] !== 'public',
            'visibility' => $item['visibility'], 'defaultBranch' => $branch ?? ''];
    }

    /** @return array{name: string, path: string, type: string} */
    private function treeEntry(mixed $item): array
    {
        if (!is_array($item) || !is_string($item['name'] ?? null) || !is_string($item['path'] ?? null)
            || !in_array($item['type'] ?? null, ['tree', 'blob', 'commit'], true) || !is_string($item['mode'] ?? null)
            || preg_match('/^[0-7]{6}$/D', $item['mode']) !== 1 || !self::validPath($item['path'])
            || preg_match('#[/\\\\\x00-\x1f]#', $item['name']) || in_array($item['name'], ['', '.', '..'], true)
            || substr($item['path'], (int) strrpos('/' . $item['path'], '/')) !== $item['name']) {
            $this->invalidResponse();
        }
        $type = match (true) {
            $item['type'] === 'tree' => 'dir',
            $item['type'] === 'commit' => 'submodule',
            $item['mode'] === '120000' => 'symlink',
            in_array($item['mode'], ['100644', '100755'], true) => 'file',
            default => 'other',
        };
        return ['name' => $item['name'], 'path' => $item['path'], 'type' => $type];
    }

    /** @return array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>} */
    private function summary(mixed $data): array
    {
        if (!is_array($data) || !is_string($data['message'] ?? null) || !is_array($data['parent_ids'] ?? null)
            || !array_is_list($data['parent_ids']) || count($data['parent_ids']) > 64) {
            $this->invalidResponse();
        }
        $sha = $this->sha($data['id'] ?? null);
        $parents = array_map(fn(mixed $parent): string => $this->sha($parent), $data['parent_ids']);
        $date = $data['authored_date'] ?? null;
        if ($date !== null && (!is_string($date) || strlen($date) > 40 || strtotime($date) === false)) {
            $this->invalidResponse();
        }
        // The same UTC form as GitHub, so dates never differ between platforms.
        if ($date !== null) {
            $date = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($date));
        }
        return ['sha' => $sha, 'message' => mb_strcut($data['message'], 0, 16384, 'UTF-8'), 'messageLimited' => strlen($data['message']) > 16384,
            'author' => is_string($data['author_name'] ?? null) ? mb_substr($data['author_name'], 0, 255) : 'Unknown author',
            'date' => $date, 'parents' => $parents];
    }

    /** SHA-1 object IDs only; GitLab's experimental SHA-256 repositories are reported as unsupported instead of as bad data. */
    private function sha(mixed $value): string
    {
        if (is_string($value) && preg_match('/^[a-f0-9]{40}$/D', $value) === 1) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1) {
            throw Failure::rejected('gitlab_unsupported_repository', 'Repositories with SHA-256 object IDs are not supported yet.');
        }
        $this->invalidResponse();
    }

    private static function validPath(string $path): bool
    {
        return $path !== '' && strlen($path) <= 1024 && !preg_match('/[\x00-\x1f\x7f\\\\]/', $path) && array_intersect(['', '.', '..'], explode('/', $path)) === [];
    }

    private static function projectPath(int $project): string
    {
        if ($project < 1) {
            throw Failure::invalid('invalid_repository', 'Invalid repository.');
        }
        return '/projects/' . $project;
    }

    /** @return array<array-key, mixed> */
    private function request(string $path, bool $list = false): array
    {
        return $this->decode($this->fetch($path), $list);
    }

    private function fetch(string $path): HttpResult
    {
        $remaining = (int) floor(($this->deadline - microtime(true)) * 1000);
        if ($remaining < 1) {
            throw Failure::upstream('gitlab_timeout', 'GitLab did not respond in time.');
        }
        $result = $this->transport->get($path, $remaining);
        // Never follow upstream Link URLs; only advance our own fixed endpoint's page.
        $this->hasNextPage = preg_match('/;\s*rel="next"/', $result->headers['link'] ?? '') === 1
            || preg_match('/^[1-9][0-9]*$/D', $result->headers['x-next-page'] ?? '') === 1;
        $delay = RateLimit::delay($result);
        if ($delay !== null) {
            throw Failure::rateLimited('gitlab_rate_limited', 'GitLab request quota is temporarily unavailable.', $delay);
        }
        return $result;
    }

    /** @return array<array-key, mixed> */
    private function decode(HttpResult $result, bool $list): array
    {
        $error = match ($result->status) {
            200 => null,
            401 => ['gitlab_credentials_rejected', 'GitLab credentials were rejected.'],
            403 => ['gitlab_access_denied', 'GitLab denied repository access.'],
            404 => ['gitlab_not_found', 'The repository or file is unavailable to this token.'],
            default => ['gitlab_unavailable', 'GitLab could not complete this request.'],
        };
        if ($error !== null) {
            throw Failure::upstream($error[0], $error[1], $result->status >= 500 ? RateLimit::retryAfter($result) : null);
        }
        if (strlen($result->body) > HttpsGet::MAX_BYTES) {
            throw Failure::upstream('gitlab_response_too_large', 'GitLab returned a response beyond the preview limit.');
        }
        try {
            $data = json_decode($result->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalidResponse();
        }
        if (!is_array($data) || ($list ? !array_is_list($data) : array_is_list($data))) {
            $this->invalidResponse();
        }
        return $data;
    }

    private function invalidResponse(): never
    {
        throw Failure::upstream('gitlab_invalid_response', 'GitLab returned an unexpected response.');
    }
}
