<?php

declare(strict_types=1);

namespace Glimpse\Tests\Fixtures;

use Closure;
use Glimpse\Source\HttpResult;
use Glimpse\Source\Transport;

/** Deterministic test data; never included in the production package. */
final class RepositoryTransport implements Transport
{
    public const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public int $status = 200;
    public int $identityOffset = 0;
    public string $head = self::SHA;
    public bool $diverged = false;
    public bool $truncatedTree = false;
    /** @var list<string> Paths left out of the Git tree, as if deleted or renamed on the current head. */
    public array $treeExclude = [];
    /** @var list<string> */
    public array $missingFiles = [];
    /** @var list<string> */
    public array $paths = [];
    public ?Closure $beforeRequest = null;
    /** @var array<string, array{files: list<array<string, mixed>>, message?: string}>|null Commits newest first; replaces the default history. */
    public ?array $history = null;

    public function get(string $path, int $timeoutMilliseconds, ?string $etag = null): HttpResult
    {
        $this->paths[] = $path;
        if ($this->beforeRequest !== null) {
            ($this->beforeRequest)();
        }
        if ($this->status !== 200) {
            return $this->json(['message' => 'Private upstream error'], $this->status);
        }
        if ($path === '/user') {
            return $this->json(['id' => 4242, 'login' => 'fixture-user']);
        }
        if (preg_match('#^/user/repos\?per_page=30&page=([0-9]+)&sort=full_name&direction=asc$#D', $path, $page)) {
            return match ((int) $page[1]) {
                1 => $this->json([$this->metadata('alpha')], headers: ['link' => '<https://api.github.com/user/repos?page=2>; rel="next"']),
                2 => $this->json([$this->metadata('beta')]),
                default => $this->json([]),
            };
        }
        if (!preg_match('#^/repos/fixture/(alpha|beta)(.*)$#D', $path, $parts)) {
            return $this->json([], 404);
        }
        $name = $parts[1];
        $suffix = $parts[2];
        if ($suffix === '') {
            return $this->json($this->metadata($name));
        }
        if (str_starts_with($suffix, '/branches?')) {
            return $this->json([['name' => 'main'], ['name' => 'feature/preview'], ['name' => 'private']]);
        }
        if (str_starts_with($suffix, '/branches/')) {
            $branch = rawurldecode(substr($suffix, 10));
            if (!in_array($branch, ['main', 'feature/preview', 'private'], true)) {
                return $this->json([], 404);
            }
            return $this->json(['name' => $branch, 'commit' => ['sha' => $branch === 'main' ? $this->head : str_repeat($branch === 'private' ? 'f' : 'c', 40)]]);
        }
        if (preg_match('#^/compare/([a-f0-9]{40})\.\.\.([a-f0-9]{40})\?per_page=1&page=1$#D', $suffix, $compare)) {
            $ancestor = !$this->diverged && in_array($compare[1][0], ['a', 'b', 'c', 'd'], true);
            return $this->json(['status' => $ancestor ? 'ahead' : 'diverged', 'base_commit' => ['sha' => $compare[1]],
                'merge_base_commit' => ['sha' => $ancestor ? $compare[1] : str_repeat('0', 40)]]);
        }
        // Each commit gets its own tree ID so a moved head never reuses the cached tree of another commit.
        if (preg_match('#^/git/commits/([a-f0-9]{40})$#D', $suffix, $git)) {
            return $this->json(['sha' => $git[1], 'tree' => ['sha' => hash('sha1', 'tree:' . $git[1])]]);
        }
        if (str_starts_with($suffix, '/git/trees/')) {
            return $this->json(['truncated' => $this->truncatedTree, 'tree' => array_values(array_filter([
                ['path' => 'docs', 'mode' => '040000', 'type' => 'tree'],
                ['path' => 'docs/čitaj me.txt', 'mode' => '100644', 'type' => 'blob', 'size' => 100],
                ['path' => 'App.xaml.cs', 'mode' => '100644', 'type' => 'blob', 'size' => 200],
                ['path' => 'App.xaml', 'mode' => '100644', 'type' => 'blob', 'size' => 200],
                ['path' => 'README.md', 'mode' => '100644', 'type' => 'blob', 'size' => 100],
                ['path' => 'docs-link', 'mode' => '120000', 'type' => 'blob', 'size' => 4],
                ['path' => 'docs-submodule', 'mode' => '160000', 'type' => 'commit'],
            ], fn(array $item): bool => !in_array($item['path'], $this->treeExclude, true)))]);
        }
        if ($this->history !== null && str_starts_with($suffix, '/commits?')) {
            return $this->historyPage($name, $suffix);
        }
        if ($this->history !== null && preg_match('#^/commits/([a-f0-9]{40})\?per_page=30&page=(\d+)$#D', $suffix, $detail) && isset($this->history[$detail[1]])) {
            return $this->historyDetail($name, $detail[1], (int) $detail[2]);
        }
        if (str_starts_with($suffix, '/commits?')) {
            parse_str((string) parse_url($suffix, PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $sha = is_string($query['sha'] ?? null) ? $query['sha'] : $this->head;
            $items = $page === 1 ? [$this->commitSummary($sha), $this->commitSummary(str_repeat('c', 40)), $this->commitSummary(str_repeat('d', 40))]
                : [$this->commitSummary(str_repeat('b', 40))];
            return $this->json($items, headers: $page === 1 ? ['link' => '<https://api.github.com/repos/fixture/' . $name . '/commits?page=2>; rel="next"'] : []);
        }
        if (preg_match('#^/commits/([a-f0-9]{40})\?per_page=30&page=(\d+)$#D', $suffix, $detail)) {
            $sha = $detail[1];
            $page = (int) $detail[2];
            $files = $page === 1 ? [
                ['filename' => 'App.xaml.cs', 'status' => 'modified', 'additions' => 6, 'deletions' => 1,
                    'patch' => "@@ -1,5 +1,10 @@\n using System;\n namespace Glimpse;\n public partial class App\n {\n-    private string title = \"App\";\n+    private string title = \"Glimpse\";\n+    // Startup: čćžšđ\n+    private void Start()\n+    {\n+        Console.WriteLine(title);\n+    }"],
                ['filename' => 'docs/čitaj me.txt', 'previous_filename' => 'old name.txt', 'status' => 'renamed', 'additions' => 1, 'deletions' => 1,
                    'patch' => "@@ -1 +1 @@\n-old\n+<script>window.fixtureExecuted=true</script>"],
                ['filename' => 'logo.png', 'status' => 'modified', 'additions' => 0, 'deletions' => 0],
            ] : [
                ['filename' => 'README.md', 'status' => 'removed', 'additions' => 0, 'deletions' => 1, 'patch' => "@@ -1 +0,0 @@\n-Old README"],
            ];
            return $this->json(
                $this->commitSummary($sha) + ['files' => $files, 'stats' => ['additions' => 7, 'deletions' => 3, 'total' => 10]],
                headers: $page === 1 ? ['link' => '<https://api.github.com/repos/fixture/' . $name . '/commits/' . $sha . '?page=2>; rel="next"'] : [],
            );
        }
        if (str_starts_with($suffix, '/commits/')) {
            $ref = rawurldecode(substr($suffix, strlen('/commits/')));
            return $this->json(['sha' => preg_match('/^[a-f0-9]{40}$/D', $ref) ? $ref : $this->head]);
        }
        if (!preg_match('#^/contents/(.*)\?ref=([a-f0-9]{40})$#D', $suffix, $content)) {
            return $this->json([], 404);
        }
        $file = rawurldecode($content[1]);
        if (in_array($content[2] . ':' . $file, $this->missingFiles, true)) {
            return $this->json([], 404);
        }
        if ($file === '') {
            return $this->json([
                ['name' => 'README.md', 'path' => 'README.md', 'type' => 'file', 'size' => 100],
                ['name' => 'docs', 'path' => 'docs', 'type' => 'dir', 'size' => 0],
                ['name' => 'App.xaml.cs', 'path' => 'App.xaml.cs', 'type' => 'file', 'size' => 200],
                ['name' => 'App.xaml', 'path' => 'App.xaml', 'type' => 'file', 'size' => 200],
            ]);
        }
        if ($file === 'docs') {
            return $this->json([['name' => 'čitaj me.txt', 'path' => 'docs/čitaj me.txt', 'type' => 'file', 'size' => 100]]);
        }
        if (!in_array($file, ['README.md', 'docs/čitaj me.txt', 'App.xaml.cs', 'App.xaml'], true)) {
            return $this->json([], 404);
        }
        $text = match ($file) {
            'App.xaml.cs' => "using System;\n\nnamespace Glimpse;\n\n// Application startup: čćžšđ\npublic partial class App\n{\n    private string title = \"Glimpse\";\n    private int count = 42;\n}\n",
            'App.xaml' => '<Window Title="Glimpse"><TextBlock Text="Hello" /><script>window.fixtureExecuted=true</script></Window>',
            default => "# Private " . $name . "\nFixture content: čćžšđ\n<script>window.fixtureExecuted=true</script>\n",
        };
        return $this->json(['path' => $file, 'type' => 'file', 'size' => strlen($text), 'encoding' => 'base64', 'content' => base64_encode($text)]);
    }

    /** Like GitHub, a path filter lists commits that changed that path or a file below it; a copy leaves its source unchanged. */
    private function historyPage(string $name, string $suffix): HttpResult
    {
        parse_str((string) parse_url($suffix, PHP_URL_QUERY), $query);
        $path = is_string($query['path'] ?? null) ? $query['path'] : '';
        $page = (int) ($query['page'] ?? 1);
        $shas = array_keys($this->history ?? []);
        $start = array_search($query['sha'] ?? '', $shas, true);
        $shas = array_slice($shas, $start === false ? 0 : $start);
        $shas = array_values(array_filter($shas, function (string $sha) use ($path): bool {
            if ($path === '') {
                return true;
            }
            foreach ($this->history[$sha]['files'] ?? [] as $file) {
                $paths = [$file['filename']];
                if (($file['status'] ?? '') === 'renamed') {
                    $paths[] = $file['previous_filename'];
                }
                foreach ($paths as $changed) {
                    if ($changed === $path || str_starts_with((string) $changed, $path . '/')) {
                        return true;
                    }
                }
            }
            return false;
        }));
        $items = array_map(fn(string $sha): array => $this->commitSummary($sha), array_slice($shas, ($page - 1) * 30, 30));
        return $this->json($items, headers: count($shas) > $page * 30 ? ['link' => '<https://api.github.com/repos/fixture/' . $name . '/commits?page=' . ($page + 1) . '>; rel="next"'] : []);
    }

    private function historyDetail(string $name, string $sha, int $page): HttpResult
    {
        $all = $this->history[$sha]['files'] ?? [];
        $files = array_slice($all, ($page - 1) * 30, 30);
        $additions = array_sum(array_column($all, 'additions'));
        $deletions = array_sum(array_column($all, 'deletions'));
        return $this->json(
            $this->commitSummary($sha) + ['files' => $files, 'stats' => ['additions' => $additions, 'deletions' => $deletions, 'total' => $additions + $deletions]],
            headers: count($all) > $page * 30 ? ['link' => '<https://api.github.com/repos/fixture/' . $name . '/commits/' . $sha . '?page=' . ($page + 1) . '>; rel="next"'] : [],
        );
    }

    /** @return array<string, mixed> */
    private function commitSummary(string $sha): array
    {
        if (isset($this->history[$sha])) {
            $shas = array_keys($this->history);
            $next = $shas[array_search($sha, $shas, true) + 1] ?? null;
            return ['sha' => $sha, 'commit' => ['message' => $this->history[$sha]['message'] ?? 'Change', 'author' => ['name' => 'Hidden Author', 'date' => '2026-09-14T07:24:00Z']],
                'parents' => $next === null ? [] : [['sha' => $next]]];
        }
        return ['sha' => $sha, 'commit' => ['message' => match ($sha[0]) {
            'b' => 'Initial project setup', 'c' => 'Merge startup improvements', 'd' => "Update documentation\n\nPreserve <script> as text.", default => 'Improve application startup',
        }, 'author' => ['name' => 'Alex Doe', 'date' => '2026-09-14T07:24:00Z']],
            'parents' => $sha[0] === 'b' ? [] : ($sha[0] === 'c' ? [['sha' => str_repeat('a', 40)], ['sha' => str_repeat('b', 40)]] : [['sha' => str_repeat('b', 40)]])];
    }

    /** @return array<string, mixed> */
    private function metadata(string $name): array
    {
        return ['id' => ($name === 'alpha' ? 101 : 102) + $this->identityOffset, 'full_name' => 'fixture/' . $name,
            'private' => true, 'default_branch' => 'main', 'description' => 'Private project for browser verification'];
    }

    /** @param array<array-key, mixed> $data
     * @param array<string, string> $headers
     */
    private function json(array $data, int $status = 200, array $headers = []): HttpResult
    {
        return new HttpResult($status, $headers, json_encode($data, JSON_THROW_ON_ERROR));
    }
}
