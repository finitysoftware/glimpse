<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Glimpse\Failure;
use Glimpse\Source\BranchName;

/** What a link visitor may browse: the link's branches, whether History is allowed, and which files. */
final readonly class BrowseAccess
{
    /** @param list<string> $branches The link's branches, at least one. The policy, when present, limits files per branch. */
    public function __construct(public array $branches, public bool $history, public ?FilePolicy $policy = null) {}

    /** @return list<string> */
    public static function selected(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 50) {
            self::invalid();
        }
        foreach ($value as $branch) {
            if (!is_string($branch) || !BranchName::valid($branch)) {
                self::invalid();
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            self::invalid();
        }
        return $value;
    }

    /** The branches of a link as its row stores them.
     * @return list<string>
     */
    public static function stored(string $json): array
    {
        return self::selected(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
    }

    /** The requested branch; without one, the repository's default branch when the link shares it, or else the link's first branch. */
    public function branch(string $requested, string $default): string
    {
        $branch = $requested !== '' ? $requested : (in_array($default, $this->branches, true) ? $default : $this->branches[0]);
        if (!in_array($branch, $this->branches, true)) {
            throw Failure::forbidden('branch_not_allowed', 'This branch is not available through this link.');
        }
        return $branch;
    }

    private static function invalid(): never
    {
        throw Failure::invalid('invalid_branches', 'Select between 1 and 50 valid branches.');
    }

    /** Whether a folder has at least one visible file below it; the root is always shown.
     * @param array<string, true> $paths
     */
    public static function folderVisible(array $paths, string $folder): bool
    {
        if ($folder === '') {
            return true;
        }
        foreach (array_keys($paths) as $path) {
            if (str_starts_with((string) $path, $folder . '/')) {
                return true;
            }
        }
        return false;
    }
}
