<?php

declare(strict_types=1);

namespace Glimpse\GitHub;

use Glimpse\Failure;
use Glimpse\Source\BranchName;
use Glimpse\Source\CommitData;
use Glimpse\Source\HttpsGet;
use Glimpse\Source\ManifestSearch;
use Glimpse\Source\RateLimit;
use Glimpse\Source\RepositoryClient;
use Glimpse\Source\Transport;
use JsonException;

/**
 * @phpstan-import-type CommitHistory from RepositoryClient
 * @phpstan-import-type CommitDetail from RepositoryClient
 */
final class GitHubClient
{
    private readonly float $deadline;
    private bool $hasNextPage = false;

    public function __construct(private readonly Transport $transport)
    {
        $this->deadline = microtime(true) + 15;
    }

    /** @return array{items: list<array{id: int, fullName: string, owner: string, name: string, private: bool, defaultBranch: string, description: string}>, nextPage: int|null} */
    public function repositories(int $page): array
    {
        if ($page < 1 || $page > 10000) {
            throw Failure::invalid('invalid_page', 'Invalid repository page.');
        }
        $data = $this->request('/user/repos?per_page=30&page=' . $page . '&sort=full_name&direction=asc', true);
        $items = [];
        foreach ($data as $item) {
            if (!is_array($item) || !is_int($item['id'] ?? null) || $item['id'] < 1
                || !is_string($item['full_name'] ?? null) || !is_bool($item['private'] ?? null)
                || !is_string($item['default_branch'] ?? null)) {
                $this->invalidResponse();
            }
            $parts = explode('/', $item['full_name']);
            if (count($parts) !== 2) {
                $this->invalidResponse();
            }
            [$owner, $name] = $parts;
            $this->repositoryPath($owner, $name);
            $items[] = ['id' => $item['id'], 'fullName' => $item['full_name'], 'owner' => $owner, 'name' => $name,
                'private' => $item['private'], 'defaultBranch' => $item['default_branch'],
                'description' => is_string($item['description'] ?? null) ? mb_substr($item['description'], 0, 500) : ''];
        }
        return ['items' => $items, 'nextPage' => $this->hasNextPage ? $page + 1 : null];
    }

    /** Identity of the token's GitHub account; used to verify a token before it is saved.
     * @return array{id: int, login: string}
     */
    public function user(): array
    {
        $data = $this->request('/user');
        if (!is_int($data['id'] ?? null) || $data['id'] < 1 || !is_string($data['login'] ?? null)
            || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/D', $data['login']) !== 1) {
            $this->invalidResponse();
        }
        return ['id' => $data['id'], 'login' => $data['login']];
    }

    /** @return array{entries: list<array{name: string, path: string, type: string, size: int}>, limited: bool} */
    public function directory(string $owner, string $repository, string $path, string $sha): array
    {
        $segments = $path === '' ? [] : explode('/', $path);
        if (strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)
            || array_intersect(['', '.', '..'], $segments) !== [] || !preg_match('/^[a-f0-9]{40}$/D', $sha)) {
            throw Failure::invalid('invalid_path', 'Invalid folder path or commit SHA.');
        }
        $data = $this->request($this->repositoryPath($owner, $repository) . '/contents/'
            . implode('/', array_map('rawurlencode', $segments)) . '?ref=' . $sha, true);
        $entries = [];
        foreach ($data as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || !is_string($item['path'] ?? null)
                || !is_string($item['type'] ?? null) || !is_int($item['size'] ?? null)
                || $item['path'] !== ($path === '' ? '' : $path . '/') . $item['name']
                || preg_match('#[/\\\\\x00-\x1f]#', $item['name']) || in_array($item['name'], ['', '.', '..'], true)) {
                $this->invalidResponse();
            }
            $type = isset($item['submodule_git_url']) ? 'submodule' : $item['type'];
            $entries[] = ['name' => $item['name'], 'path' => $item['path'], 'type' => $type, 'size' => $item['size']];
        }
        usort($entries, static fn(array $a, array $b): int => (($b['type'] === 'dir') <=> ($a['type'] === 'dir')) ?: strnatcasecmp($a['name'], $b['name']));
        return ['entries' => $entries, 'limited' => count($entries) >= 1000];
    }

    /** @return array{id: int, fullName: string, private: bool, defaultBranch: string} */
    public function metadata(string $owner, string $repository): array
    {
        $data = $this->request($this->repositoryPath($owner, $repository));
        if (!is_int($data['id'] ?? null) || !is_string($data['full_name'] ?? null)
            || !is_bool($data['private'] ?? null) || !is_string($data['default_branch'] ?? null)
            || strcasecmp($data['full_name'], $owner . '/' . $repository) !== 0) {
            $this->invalidResponse();
        }
        return ['id' => $data['id'], 'fullName' => $data['full_name'], 'private' => $data['private'], 'defaultBranch' => $data['default_branch']];
    }

    public function resolveCommit(string $owner, string $repository, string $ref): string
    {
        if ($ref === '' || strlen($ref) > 255 || preg_match('/[\x00-\x20\x7f]/', $ref)) {
            throw Failure::invalid('invalid_ref', 'Invalid repository revision.');
        }
        $data = $this->request($this->repositoryPath($owner, $repository) . '/commits/' . rawurlencode($ref));
        $sha = $data['sha'] ?? null;
        if (!is_string($sha) || preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            $this->invalidResponse();
        }
        return $sha;
    }

    /** @return array{items: list<string>, nextPage: int|null} */
    public function branches(string $owner, string $repository, int $page): array
    {
        if ($page < 1 || $page > 10000) {
            throw Failure::invalid('invalid_page', 'Invalid branch page.');
        }
        $data = $this->request($this->repositoryPath($owner, $repository) . '/branches?per_page=100&page=' . $page, true);
        if (count($data) > 100) {
            $this->invalidResponse();
        }
        $items = [];
        foreach ($data as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null)
                || !BranchName::valid($item['name'])) {
                $this->invalidResponse();
            }
            $items[] = $item['name'];
        }
        return ['items' => $items, 'nextPage' => $this->hasNextPage && $page < 10000 ? $page + 1 : null];
    }

    public function branchHead(string $owner, string $repository, string $branch): string
    {
        if (!BranchName::valid($branch)) {
            throw Failure::invalid('invalid_branch', 'Invalid branch name.');
        }
        $data = $this->request($this->repositoryPath($owner, $repository) . '/branches/' . rawurlencode($branch));
        if (($data['name'] ?? null) !== $branch || !is_array($data['commit'] ?? null)
            || !is_string($data['commit']['sha'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $data['commit']['sha'])) {
            $this->invalidResponse();
        }
        return $data['commit']['sha'];
    }

    public function isAncestor(string $owner, string $repository, string $sha, string $head): bool
    {
        CommitData::validateQuery($sha, '', 1, 1);
        CommitData::validateQuery($head, '', 1, 1);
        if ($sha === $head) {
            return true;
        }
        $data = $this->request($this->repositoryPath($owner, $repository) . '/compare/' . $sha . '...' . $head . '?per_page=1&page=1');
        if (!is_array($data['base_commit'] ?? null) || !is_array($data['merge_base_commit'] ?? null)
            || ($data['base_commit']['sha'] ?? null) !== $sha || !is_string($data['merge_base_commit']['sha'] ?? null)
            || !in_array($data['status'] ?? null, ['ahead', 'behind', 'identical', 'diverged'], true)) {
            $this->invalidResponse();
        }
        return in_array($data['status'], ['ahead', 'identical'], true) && $data['merge_base_commit']['sha'] === $sha;
    }

    /** Results and pages are computed only over $visible paths when a link limits files, so hidden matches never appear in counts.
     * @param array<string, true>|null $visible
     * @return array{items: list<array{name: string, path: string, type: string, size: int|null}>, page: int, nextPage: int|null, limited: bool}
     */
    public function search(string $owner, string $repository, string $sha, string $query, int $page, ?array $visible = null): array
    {
        return ManifestSearch::search($sha, $query, $page, $visible, fn(): array => $this->files($owner, $repository, $sha));
    }

    /** Regular files of a commit from Git tree metadata, without downloading content. Symlinks and submodules are excluded.
     * @return array{files: array<string, int>, truncated: bool} Sizes keyed by exact, case-sensitive path.
     */
    public function files(string $owner, string $repository, string $sha): array
    {
        CommitData::validateQuery($sha, '', 1, 1);
        $base = $this->repositoryPath($owner, $repository);
        $commit = $this->request($base . '/git/commits/' . $sha);
        if (($commit['sha'] ?? null) !== $sha || !is_array($commit['tree'] ?? null)
            || !is_string($commit['tree']['sha'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $commit['tree']['sha'])) {
            $this->invalidResponse();
        }
        $data = $this->request($base . '/git/trees/' . $commit['tree']['sha'] . '?recursive=1');
        if (!is_array($data['tree'] ?? null) || !array_is_list($data['tree']) || !is_bool($data['truncated'] ?? null)) {
            $this->invalidResponse();
        }
        $files = [];
        foreach ($data['tree'] as $item) {
            if (!is_array($item) || !is_string($item['path'] ?? null) || !is_string($item['type'] ?? null)
                || !is_string($item['mode'] ?? null)) {
                $this->invalidResponse();
            }
            $path = $item['path'];
            if (strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)
                || array_intersect(['', '.', '..'], explode('/', $path)) !== []) {
                $this->invalidResponse();
            }
            if ($item['type'] !== 'blob' || !in_array($item['mode'], ['100644', '100755'], true)) {
                continue;
            }
            if (!is_int($item['size'] ?? null) || $item['size'] < 0) {
                $this->invalidResponse();
            }
            $files[$path] = $item['size'];
        }
        return ['files' => $files, 'truncated' => $data['truncated']];
    }

    /** @return CommitHistory */
    public function history(string $owner, string $repository, string $sha, string $path, int $page): array
    {
        $list = $this->commitPage($owner, $repository, $sha, $path, $page);
        return ['items' => $list['items'], 'page' => $page, 'nextPage' => $list['hasNext'] && $page < 1000000 ? $page + 1 : null,
            'limited' => $list['hasNext'] && $page === 1000000];
    }

    /** One page of 30 commits reachable from $sha, optionally only those GitHub lists for $path.
     * @return array{items: list<array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>}>, hasNext: bool}
     */
    public function commitPage(string $owner, string $repository, string $sha, string $path, int $page): array
    {
        CommitData::validateQuery($sha, $path, $page, 1000000);
        $data = $this->request($this->repositoryPath($owner, $repository) . '/commits?'
            . http_build_query(['sha' => $sha, 'path' => $path, 'per_page' => 30, 'page' => $page], '', '&', PHP_QUERY_RFC3986), true);
        if (count($data) > 30) {
            $this->invalidResponse();
        }
        return ['items' => array_values(array_map(static fn(mixed $item): array => CommitData::summary($item), $data)), 'hasNext' => $this->hasNextPage];
    }

    /** @return CommitDetail */
    public function commit(string $owner, string $repository, string $sha, int $page): array
    {
        CommitData::validateQuery($sha, '', $page, 100);
        $data = $this->request($this->repositoryPath($owner, $repository) . '/commits/' . $sha . '?per_page=30&page=' . $page);
        return CommitData::detail($data, $sha, $page, $this->hasNextPage);
    }

    /** One GitHub page of a commit's changed files (same request and cache entry as commit()).
     * @return array{commit: array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>},
     *   files: list<array{path: string, previousPath: string|null, status: string, additions: int, deletions: int, patch: string|null}>,
     *   stats: array{additions: int, deletions: int, total: int}, hasNext: bool}
     */
    public function commitFiles(string $owner, string $repository, string $sha, int $page): array
    {
        CommitData::validateQuery($sha, '', $page, 100);
        $data = $this->request($this->repositoryPath($owner, $repository) . '/commits/' . $sha . '?per_page=30&page=' . $page);
        return CommitData::changedFiles($data, $sha) + ['hasNext' => $this->hasNextPage && $page < 100];
    }

    /** @return array{path: string, commitSha: string, bytes: int, content: string} */
    public function file(string $owner, string $repository, string $path, string $sha): array
    {
        $segments = explode('/', $path);
        if ($path === '' || strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)
            || array_intersect(['', '.', '..'], $segments) !== [] || preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw Failure::invalid('invalid_path', 'Invalid file path or commit SHA.');
        }
        $data = $this->request($this->repositoryPath($owner, $repository) . '/contents/'
            . implode('/', array_map('rawurlencode', $segments)) . '?ref=' . $sha);
        if (($data['type'] ?? '') !== 'file' || ($data['encoding'] ?? '') !== 'base64'
            || !is_int($data['size'] ?? null) || !is_string($data['content'] ?? null)
            || ($data['path'] ?? null) !== $path) {
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

    private function repositoryPath(string $owner, string $repository): string
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{0,38}$/D', $owner)
            || !preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $repository) || in_array($repository, ['.', '..'], true)) {
            throw Failure::invalid('invalid_repository', 'Invalid repository owner or name.');
        }
        return '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repository);
    }

    /** @return array<array-key, mixed> */
    private function request(string $path, bool $list = false): array
    {
        $remaining = (int) floor(($this->deadline - microtime(true)) * 1000);
        if ($remaining < 1) {
            throw Failure::upstream('github_timeout', 'GitHub did not respond in time.');
        }
        $result = $this->transport->get($path, $remaining);
        // Never follow upstream Link URLs; only advance our own fixed endpoint's page.
        $this->hasNextPage = preg_match('/;\s*rel="next"/', $result->headers['link'] ?? '') === 1;
        $delay = RateLimit::delay($result);
        if ($delay !== null) {
            throw Failure::rateLimited('github_rate_limited', 'GitHub request quota is temporarily unavailable.', $delay);
        }
        $error = match ($result->status) {
            200 => null,
            401 => ['github_credentials_rejected', 'GitHub credentials were rejected.'],
            403 => ['github_access_denied', 'GitHub denied repository access.'],
            404 => ['github_not_found', 'The repository or file is unavailable to this installation.'],
            409 => ['github_empty_repository', 'This repository has no available commit.'],
            default => ['github_unavailable', 'GitHub could not complete this request.'],
        };
        if ($error !== null) {
            throw Failure::upstream($error[0], $error[1], $result->status >= 500 ? RateLimit::retryAfter($result) : null);
        }
        if (strlen($result->body) > HttpsGet::MAX_BYTES) {
            throw Failure::upstream('github_response_too_large', 'GitHub returned a response beyond the preview limit.');
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
        throw Failure::upstream('github_invalid_response', 'GitHub returned an unexpected response.');
    }
}
