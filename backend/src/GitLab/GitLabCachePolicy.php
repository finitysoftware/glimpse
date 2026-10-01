<?php

declare(strict_types=1);

namespace Glimpse\GitLab;

use Glimpse\Source\CachePolicy;
use Glimpse\Source\CacheRule;

/** GitLab API paths (relative to /api/v4): data addressed by commit SHA is immutable; the catalogue, projects, branches and refs live 30 seconds.
 * Pages of the recursive tree are not stored one by one; the client caches the assembled manifest as one derived entry.
 */
final readonly class GitLabCachePolicy implements CachePolicy
{
    private const SHA = '[a-f0-9]{40}';

    public function rule(string $path): CacheRule
    {
        $tree = preg_match('#/repository/tree\?(?:[^&]*&)*ref=' . self::SHA . '(?:&|$)#D', $path) === 1;
        $file = preg_match('#/repository/files/[^/?]+\?ref=' . self::SHA . '$#D', $path) === 1;
        $history = preg_match('#/repository/commits\?ref_name=' . self::SHA . '(?:&|$)#D', $path) === 1;
        $commit = preg_match('#/repository/commits/' . self::SHA . '(?:\?stats=true|/diff\?per_page=30&page=[1-9][0-9]*)$#D', $path) === 1;
        $exact = preg_match('#/repository/(?:commits/' . self::SHA . '|merge_base\?refs%5B%5D=' . self::SHA . '&refs%5B%5D=' . self::SHA . ')$#D', $path) === 1;
        $content = $tree || $file || $history || $commit;
        $metadata = preg_match('#^/projects/[1-9][0-9]*$#D', $path) === 1 || str_starts_with($path, '/projects?');
        return new CacheRule($content, $metadata, $content || $exact, !($tree && str_contains($path, 'recursive=true')));
    }
}
