<?php

declare(strict_types=1);

namespace Glimpse\Visibility;

use Glimpse\Failure;

/** Repository-relative, case-sensitive paths. Folders match whole segments only, so "src" never matches "src-secret". */
final class VisibilityPaths
{
    public const MAX_PATH_BYTES = 1024;

    /** The empty string is the repository root. The value is compared exactly as received; it is never URL-decoded or case-folded. */
    public static function valid(string $path): bool
    {
        if ($path === '') {
            return true;
        }
        return strlen($path) <= self::MAX_PATH_BYTES && mb_check_encoding($path, 'UTF-8')
            && preg_match('/[\x00-\x1f\x7f\\\\]/', $path) !== 1
            && array_intersect(['', '.', '..'], explode('/', $path)) === [];
    }

    public static function require(string $path): string
    {
        if (!self::valid($path)) {
            throw Failure::invalid('invalid_visibility_path', 'Choose a valid file or folder path.');
        }
        return $path;
    }

    /** Whether $path is $folder itself or inside it; the root contains every path. */
    public static function within(string $path, string $folder): bool
    {
        return $folder === '' || $path === $folder || str_starts_with($path, $folder . '/');
    }

    public static function hash(string $path): string
    {
        return hash('sha256', $path, true);
    }

    /** Every parent folder of a file, from the root down, excluding the file itself.
     * @return list<string>
     */
    public static function parents(string $path): array
    {
        $parents = [''];
        $parts = explode('/', $path);
        array_pop($parts);
        $current = '';
        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            $parents[] = $current;
        }
        return $parents;
    }
}
