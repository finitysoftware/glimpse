<?php

declare(strict_types=1);

namespace Glimpse\Tests\Fixtures;

use Glimpse\Failure;
use Glimpse\Source\CommitData;
use Glimpse\Source\RepositoryClient;

/** A GitHub repository in memory: branches, ancestry, files that are the same at every commit, and commits that change some of them.
 * Every read is recorded, so a test can prove that a denied request read no content.
 */
final class FakeRepository implements RepositoryClient
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param array<string, string> $heads Branch => head SHA.
     * @param array<string, list<string>> $ancestors Head SHA => older SHAs reachable from it.
     * @param array<string, string> $files Path => content.
     * @param array<string, string> $links Path => 'symlink' or 'submodule'.
     * @param list<array{sha: string, message: string, files: list<string>}> $commits Newest first.
     * @param array<string, string> $tags Tag => SHA.
     */
    public function __construct(
        private array $heads,
        private array $ancestors = [],
        private array $files = [],
        private array $links = [],
        private array $commits = [],
        private array $tags = [],
        private string $defaultBranch = 'main',
    ) {}

    public function metadata(): array
    {
        $this->calls[] = 'metadata';
        return ['id' => 42, 'fullName' => 'owner/app', 'private' => true, 'defaultBranch' => $this->defaultBranch];
    }

    public function branches(int $page): array
    {
        $this->calls[] = 'branches';
        return ['items' => array_keys($this->heads), 'nextPage' => null];
    }

    public function branchHead(string $branch): string
    {
        $this->calls[] = 'branchHead:' . $branch;
        return $this->heads[$branch] ?? self::missing();
    }

    public function resolveCommit(string $ref): string
    {
        $this->calls[] = 'resolveCommit:' . $ref;
        return $this->heads[$ref] ?? $this->tags[$ref] ?? (preg_match('/^[a-f0-9]{40}$/D', $ref) === 1 ? $ref : self::missing());
    }

    public function isAncestor(string $sha, string $head): bool
    {
        $this->calls[] = 'isAncestor:' . $sha;
        return in_array($sha, $this->ancestors[$head] ?? [], true);
    }

    public function directory(string $path, string $sha): array
    {
        $this->calls[] = 'directory:' . $path;
        $prefix = $path === '' ? '' : $path . '/';
        $entries = [];
        foreach ([...array_fill_keys(array_keys($this->files), 'file'), ...$this->links] as $file => $type) {
            $file = (string) $file;
            if (!str_starts_with($file, $prefix)) {
                continue;
            }
            $rest = substr($file, strlen($prefix));
            $name = explode('/', $rest)[0];
            $folder = str_contains($rest, '/');
            $entries[$name] = ['name' => $name, 'path' => $prefix . $name, 'type' => $folder ? 'dir' : $type,
                'size' => $folder || $type !== 'file' ? null : strlen($this->files[$file])];
        }
        if ($entries === []) {
            self::missing();
        }
        ksort($entries);
        return ['entries' => array_values($entries), 'limited' => false];
    }

    public function files(string $sha): array
    {
        $this->calls[] = 'files';
        return ['files' => array_map(strlen(...), $this->files), 'truncated' => false];
    }

    public function search(string $sha, string $query, int $page, ?array $visible): array
    {
        $this->calls[] = 'search:' . $query;
        $items = [];
        foreach ($this->files as $path => $content) {
            $path = (string) $path;
            if (str_contains($path, $query) && ($visible === null || isset($visible[$path]))) {
                $items[] = ['name' => basename($path), 'path' => $path, 'type' => 'file', 'size' => strlen($content)];
            }
        }
        return ['items' => $items, 'page' => $page, 'nextPage' => null, 'limited' => false];
    }

    public function history(string $sha, string $path, int $page): array
    {
        $this->calls[] = 'history:' . $path;
        return ['items' => $this->commitPage($sha, $path, $page)['items'], 'page' => $page, 'nextPage' => null, 'limited' => false];
    }

    public function commitPage(string $sha, string $path, int $page): array
    {
        $this->calls[] = 'commitPage:' . $path;
        $items = [];
        foreach ($this->commits as $commit) {
            if ($path === '' || array_filter($commit['files'], static fn(string $file): bool => $file === $path || str_starts_with($file, $path . '/')) !== []) {
                $items[] = $this->summary($commit);
            }
        }
        return ['items' => $items, 'hasNext' => false];
    }

    public function commit(string $sha, int $page): array
    {
        $this->calls[] = 'commit:' . $sha;
        $data = $this->commitFiles($sha, $page);
        return ['commit' => $data['commit'], 'files' => CommitData::present($data['files']), 'page' => $page, 'nextPage' => null, 'filesLimited' => false,
            'stats' => $data['stats']];
    }

    public function commitFiles(string $sha, int $page): array
    {
        $this->calls[] = 'commitFiles:' . $sha;
        foreach ($this->commits as $commit) {
            if ($commit['sha'] === $sha) {
                $files = array_map(static fn(string $path): array => ['path' => $path, 'previousPath' => null, 'status' => 'modified',
                    'additions' => 1, 'deletions' => 1, 'patch' => "@@ -1 +1 @@\n-old\n+new"], $commit['files']);
                return ['commit' => $this->summary($commit), 'files' => $files,
                    'stats' => ['additions' => count($files), 'deletions' => count($files), 'total' => 2 * count($files)], 'hasNext' => false];
            }
        }
        self::missing();
    }

    public function file(string $path, string $sha): array
    {
        $this->calls[] = 'file:' . $path;
        $content = $this->files[$path] ?? self::missing();
        return ['path' => $path, 'commitSha' => $sha, 'bytes' => strlen($content), 'content' => $content];
    }

    /** @param array{sha: string, message: string, files: list<string>} $commit
     *  @return array{sha: string, message: string, messageLimited: bool, author: string, date: string|null, parents: list<string>}
     */
    private function summary(array $commit): array
    {
        return ['sha' => $commit['sha'], 'message' => $commit['message'], 'messageLimited' => false, 'author' => 'Owner Name',
            'date' => '2026-09-14T00:00:00Z', 'parents' => []];
    }

    /** What GitHubClient reports for a missing repository, branch, commit or file. */
    public static function missing(): never
    {
        throw Failure::upstream('github_not_found', 'The repository or file is unavailable to this installation.');
    }
}
