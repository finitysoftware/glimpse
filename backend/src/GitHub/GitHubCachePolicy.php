<?php

declare(strict_types=1);

namespace Glimpse\GitHub;

use Glimpse\Source\CachePolicy;
use Glimpse\Source\CacheRule;

/** GitHub API paths: content addressed by commit SHA is immutable; catalogue and repository metadata live 30 seconds. */
final readonly class GitHubCachePolicy implements CachePolicy
{
    public function rule(string $path): CacheRule
    {
        $history = preg_match('#/commits\?sha=[a-f0-9]{40}&path=#', $path) === 1;
        $commit = preg_match('#/commits/[a-f0-9]{40}\?per_page=30&page=\d+$#D', $path) === 1;
        $git = preg_match('#/git/(commits/[a-f0-9]{40}|trees/[a-f0-9]{40}\?recursive=1)$#D', $path) === 1;
        $compare = preg_match('#/compare/[a-f0-9]{40}\.\.\.[a-f0-9]{40}\?per_page=1&page=1$#D', $path) === 1;
        $content = str_contains($path, '/contents/') || $history || $commit || $git;
        $metadata = preg_match('#^/repos/[^/]+/[^/?]+$#D', $path) === 1 || str_starts_with($path, '/user/repos?');
        // Commit details use GitHub's fixed parent semantics; SHA + page identifies that diff.
        return new CacheRule($content, $metadata, $content || $compare || preg_match('#/commits/[a-f0-9]{40}$#D', $path) === 1);
    }
}
