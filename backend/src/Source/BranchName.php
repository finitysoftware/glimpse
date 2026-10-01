<?php

declare(strict_types=1);

namespace Glimpse\Source;

/** Branch names Glimpse accepts from users and platforms: the rules of git check-ref-format, shared by GitHub and GitLab. */
final class BranchName
{
    public static function valid(string $branch): bool
    {
        return $branch !== '' && strlen($branch) <= 255 && !preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/', $branch)
            && !str_contains($branch, '..') && !str_contains($branch, '@{') && $branch !== '@'
            && !str_ends_with($branch, '.') && !str_ends_with($branch, '.lock')
            && !str_starts_with($branch, '/') && !str_ends_with($branch, '/') && !str_contains($branch, '//');
    }
}
