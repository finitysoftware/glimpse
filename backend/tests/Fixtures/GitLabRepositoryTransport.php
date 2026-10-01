<?php

declare(strict_types=1);

namespace Glimpse\Tests\Fixtures;

use Closure;
use Glimpse\Source\HttpResult;
use Glimpse\Source\Transport;

/** Deterministic GitLab API v4 data (paths relative to /api/v4); never included in the production package.
 * Projects 42 (`group/sub/alpha`, private), 43 (`group/beta`, internal) and 44 (`solo/gamma`, public) mirror the GitHub fixture's files.
 */
final class GitLabRepositoryTransport implements Transport
{
    public const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public int $status = 200;
    public string $head = self::SHA;
    public bool $diverged = false;
    /** @var list<string>|null NULL answers 404, as an instance without the token self endpoint does. */
    public ?array $scopes = ['read_api'];
    public ?string $expiresAt = '2027-03-01';
    /** Extra generated files in the recursive tree, to exercise manifest paging. */
    public int $extraFiles = 0;
    /** @var list<string> */
    public array $treeExclude = [];
    /** @var list<string> */
    public array $missingFiles = [];
    /** @var list<string> */
    public array $paths = [];
    public ?Closure $beforeRequest = null;
    /** @var array<string, array{files: list<array<string, mixed>>, message?: string}>|null Commits newest first (GitLab diff entries); replaces the default history. */
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
            return $this->json(['id' => 5150, 'username' => 'fixture-gitlab', 'name' => 'Fixture GitLab']);
        }
        if ($path === '/personal_access_tokens/self') {
            return $this->scopes === null ? $this->json(['message' => '404 Not Found'], 404)
                : $this->json(['id' => 1, 'name' => 'Glimpse', 'scopes' => $this->scopes, 'expires_at' => $this->expiresAt, 'active' => true, 'revoked' => false, 'user_id' => 5150]);
        }
        if (preg_match('#^/projects\?membership=true&min_access_level=20&order_by=path&sort=asc&per_page=30&page=([0-9]+)$#D', $path, $page)) {
            return match ((int) $page[1]) {
                1 => $this->json([$this->project(42), $this->project(43)], headers: ['x-next-page' => '2', 'link' => '<https://gitlab.example/api/v4/projects?page=2>; rel="next"']),
                2 => $this->json([$this->project(44)], headers: ['x-next-page' => '']),
                default => $this->json([]),
            };
        }
        if (!preg_match('#^/projects/(4[234])(.*)$#D', $path, $parts)) {
            return $this->json(['message' => '404 Project Not Found'], 404);
        }
        $id = (int) $parts[1];
        $suffix = $parts[2];
        if ($suffix === '') {
            return $this->json($this->project($id));
        }
        if (str_starts_with($suffix, '/repository/branches?')) {
            return $this->json(array_map(fn(string $name): array => ['name' => $name, 'commit' => ['id' => $this->branchSha($name)]], ['main', 'feature/preview', 'private']));
        }
        if (str_starts_with($suffix, '/repository/branches/')) {
            $branch = rawurldecode(substr($suffix, strlen('/repository/branches/')));
            if (!in_array($branch, ['main', 'feature/preview', 'private'], true)) {
                return $this->json(['message' => '404 Branch Not Found'], 404);
            }
            return $this->json(['name' => $branch, 'commit' => ['id' => $this->branchSha($branch)]]);
        }
        if (preg_match('#^/repository/merge_base\?refs%5B%5D=([a-f0-9]{40})&refs%5B%5D=([a-f0-9]{40})$#D', $suffix, $bases)) {
            if ($this->diverged) {
                return $this->json(['message' => 'Could not find merge base'], 400);
            }
            return $this->json($this->commitSummary(in_array($bases[1][0], ['a', 'b', 'c', 'd'], true) ? $bases[1] : str_repeat('0', 40)));
        }
        if (str_starts_with($suffix, '/repository/tree?')) {
            return $this->tree($suffix);
        }
        if ($this->history !== null && str_starts_with($suffix, '/repository/commits?')) {
            return $this->historyPage($suffix);
        }
        if (str_starts_with($suffix, '/repository/commits?')) {
            parse_str((string) parse_url($suffix, PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $sha = is_string($query['ref_name'] ?? null) ? $query['ref_name'] : $this->head;
            $items = $page === 1 ? [$this->commitSummary($sha), $this->commitSummary(str_repeat('c', 40)), $this->commitSummary(str_repeat('d', 40))]
                : [$this->commitSummary(str_repeat('b', 40))];
            return $this->json($items, headers: $page === 1 ? ['x-next-page' => '2'] : ['x-next-page' => '']);
        }
        if (preg_match('#^/repository/commits/([a-f0-9]{40})\?stats=true$#D', $suffix, $detail)) {
            $files = $this->history[$detail[1]]['files'] ?? null;
            $lines = $files === null ? ['additions' => 7, 'deletions' => 3] : ['additions' => count($files), 'deletions' => 0];
            return $this->json($this->commitSummary($detail[1]) + ['stats' => $lines + ['total' => $lines['additions'] + $lines['deletions']]]);
        }
        if (preg_match('#^/repository/commits/([a-f0-9]{40})/diff\?per_page=30&page=([0-9]+)$#D', $suffix, $diff)) {
            return $this->diff($diff[1], (int) $diff[2]);
        }
        if (str_starts_with($suffix, '/repository/commits/')) {
            $ref = rawurldecode(substr($suffix, strlen('/repository/commits/')));
            return $this->json($this->commitSummary(preg_match('/^[a-f0-9]{40}$/D', $ref) ? $ref : $this->head));
        }
        if (!preg_match('#^/repository/files/([^/?]+)\?ref=([a-f0-9]{40})$#D', $suffix, $content)) {
            return $this->json(['message' => '404 Not Found'], 404);
        }
        $file = rawurldecode($content[1]);
        if (in_array($content[2] . ':' . $file, $this->missingFiles, true) || !in_array($file, ['README.md', 'docs/čitaj me.txt', 'App.xaml.cs', 'App.xaml'], true)) {
            return $this->json(['message' => '404 File Not Found'], 404);
        }
        $text = match ($file) {
            'App.xaml.cs' => "using System;\n\nnamespace Glimpse;\n\n// Application startup: čćžšđ\npublic partial class App\n{\n    private string title = \"Glimpse\";\n}\n",
            'App.xaml' => '<Window Title="Glimpse"><TextBlock Text="Hello" /><script>window.fixtureExecuted=true</script></Window>',
            default => "# Project " . $id . "\nFixture content: čćžšđ\n<script>window.fixtureExecuted=true</script>\n",
        };
        return $this->json(['file_name' => basename($file), 'file_path' => $file, 'size' => strlen($text), 'encoding' => 'base64',
            'content' => base64_encode($text), 'ref' => $content[2], 'blob_id' => str_repeat('9', 40)]);
    }

    private function tree(string $suffix): HttpResult
    {
        parse_str((string) parse_url($suffix, PHP_URL_QUERY), $query);
        $page = max(1, (int) ($query['page'] ?? 1));
        $entry = static fn(string $path, string $type, string $mode): array => ['id' => hash('sha1', $path), 'name' => (string) substr($path, (int) strrpos('/' . $path, '/')),
            'type' => $type, 'path' => $path, 'mode' => $mode];
        $root = [$entry('docs', 'tree', '040000'), $entry('App.xaml.cs', 'blob', '100644'), $entry('App.xaml', 'blob', '100644'),
            $entry('README.md', 'blob', '100644'), $entry('docs-link', 'blob', '120000'), $entry('docs-submodule', 'commit', '160000')];
        if (($query['recursive'] ?? '') === 'true') {
            $all = [...$root, $entry('docs/čitaj me.txt', 'blob', '100644')];
            for ($i = 1; $i <= $this->extraFiles; $i++) {
                $all[] = $entry('generated/file-' . $i . '.txt', 'blob', '100644');
            }
            $all = array_values(array_filter($all, fn(array $item): bool => !in_array($item['path'], $this->treeExclude, true)));
            $items = array_slice($all, ($page - 1) * 100, 100);
            return $this->json($items, headers: ['x-next-page' => count($all) > $page * 100 ? (string) ($page + 1) : '']);
        }
        $path = is_string($query['path'] ?? null) ? $query['path'] : '';
        if ($path === 'docs') {
            return $this->json([$entry('docs/čitaj me.txt', 'blob', '100644')]);
        }
        if ($path !== '') {
            return $this->json(['message' => '404 Tree Not Found'], 404);
        }
        return $this->json(array_values(array_filter($root, fn(array $item): bool => !in_array($item['path'], $this->treeExclude, true))));
    }

    private function diff(string $sha, int $page): HttpResult
    {
        $all = $this->history[$sha]['files'] ?? [
            ['old_path' => 'App.xaml.cs', 'new_path' => 'App.xaml.cs', 'new_file' => false, 'renamed_file' => false, 'deleted_file' => false,
                'diff' => "@@ -1,5 +1,10 @@\n using System;\n namespace Glimpse;\n public partial class App\n {\n-    private string title = \"App\";\n+    private string title = \"Glimpse\";\n+    // Startup: čćžšđ\n+    private void Start()\n+    {\n+        Console.WriteLine(title);\n+    }\n"],
            ['old_path' => 'old name.txt', 'new_path' => 'docs/čitaj me.txt', 'new_file' => false, 'renamed_file' => true, 'deleted_file' => false,
                'diff' => "--- a/old name.txt\n+++ b/docs/čitaj me.txt\n@@ -1 +1 @@\n-old\n+<script>window.fixtureExecuted=true</script>\n"],
            ['old_path' => 'logo.png', 'new_path' => 'logo.png', 'new_file' => false, 'renamed_file' => false, 'deleted_file' => false,
                'diff' => "Binary files a/logo.png and b/logo.png differ\n"],
            ['old_path' => 'huge.sql', 'new_path' => 'huge.sql', 'new_file' => true, 'renamed_file' => false, 'deleted_file' => false, 'diff' => '', 'too_large' => true],
            ['old_path' => 'README.md', 'new_path' => 'README.md', 'new_file' => false, 'renamed_file' => false, 'deleted_file' => true, 'diff' => "@@ -1 +0,0 @@\n-Old README\n"],
        ];
        $size = $this->history === null ? 4 : 30;
        return $this->json(array_slice($all, ($page - 1) * $size, $size), headers: ['x-next-page' => count($all) > $page * $size ? (string) ($page + 1) : '']);
    }

    /** Like GitLab, a path filter lists commits that changed that path or a file below it. */
    private function historyPage(string $suffix): HttpResult
    {
        parse_str((string) parse_url($suffix, PHP_URL_QUERY), $query);
        $path = is_string($query['path'] ?? null) ? $query['path'] : '';
        $page = (int) ($query['page'] ?? 1);
        $shas = array_keys($this->history ?? []);
        $start = array_search($query['ref_name'] ?? '', $shas, true);
        $shas = array_slice($shas, $start === false ? 0 : $start);
        $shas = array_values(array_filter($shas, function (string $sha) use ($path): bool {
            if ($path === '') {
                return true;
            }
            foreach ($this->history[$sha]['files'] ?? [] as $file) {
                foreach ([$file['new_path'] ?? '', $file['old_path'] ?? ''] as $changed) {
                    if ($changed === $path || str_starts_with((string) $changed, $path . '/')) {
                        return true;
                    }
                }
            }
            return false;
        }));
        $items = array_map(fn(string $sha): array => $this->commitSummary($sha), array_slice($shas, ($page - 1) * 30, 30));
        return $this->json($items, headers: ['x-next-page' => count($shas) > $page * 30 ? (string) ($page + 1) : '']);
    }

    /** @return array<string, mixed> */
    private function commitSummary(string $sha): array
    {
        if (isset($this->history[$sha])) {
            $shas = array_keys($this->history);
            $next = $shas[array_search($sha, $shas, true) + 1] ?? null;
            return ['id' => $sha, 'short_id' => substr($sha, 0, 8), 'title' => 'Change', 'message' => $this->history[$sha]['message'] ?? 'Change',
                'author_name' => 'Hidden Author', 'authored_date' => '2026-09-14T07:24:00.000+00:00', 'parent_ids' => $next === null ? [] : [$next]];
        }
        $message = match ($sha[0]) {
            'b' => 'Initial project setup', 'c' => 'Merge startup improvements', 'd' => "Update documentation\n\nPreserve <script> as text.", default => 'Improve application startup',
        };
        return ['id' => $sha, 'short_id' => substr($sha, 0, 8), 'title' => strtok($message, "\n"), 'message' => $message, 'author_name' => 'Alex Doe',
            'authored_date' => '2026-09-14T07:24:00.000+00:00',
            'parent_ids' => $sha[0] === 'b' ? [] : ($sha[0] === 'c' ? [str_repeat('a', 40), str_repeat('b', 40)] : [str_repeat('b', 40)])];
    }

    private function branchSha(string $branch): string
    {
        return $branch === 'main' ? $this->head : str_repeat($branch === 'private' ? 'f' : 'c', 40);
    }

    /** @return array<string, mixed> */
    private function project(int $id): array
    {
        [$path, $visibility] = match ($id) {
            42 => ['group/sub/alpha', 'private'], 43 => ['group/beta', 'internal'], default => ['solo/gamma', 'public'],
        };
        return ['id' => $id, 'name' => ucfirst(basename($path)), 'path' => basename($path), 'path_with_namespace' => $path,
            'namespace' => ['full_path' => dirname($path)], 'visibility' => $visibility, 'default_branch' => 'main', 'empty_repo' => false,
            'archived' => false, 'description' => $id === 43 ? null : 'GitLab project for browser verification', 'web_url' => 'https://gitlab.example/' . $path];
    }

    /** @param array<array-key, mixed> $data
     * @param array<string, string> $headers
     */
    private function json(array $data, int $status = 200, array $headers = []): HttpResult
    {
        return new HttpResult($status, $headers, json_encode($data, JSON_THROW_ON_ERROR));
    }
}
