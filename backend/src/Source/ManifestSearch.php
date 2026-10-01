<?php

declare(strict_types=1);

namespace Glimpse\Source;

use Glimpse\Failure;

/** File search over a commit's manifest; the same for every platform. */
final class ManifestSearch
{
    public const PAGE = 50;

    /** Results and pages are computed only over $visible paths when a link limits files, so hidden matches never appear in counts.
     * @param callable(): array{files: array<array-key, int|null>, truncated: bool} $manifest Loaded only after the query is validated.
     * @param array<string, true>|null $visible
     * @return array{items: list<array{name: string, path: string, type: string, size: int|null}>, page: int, nextPage: int|null, limited: bool}
     */
    public static function search(string $sha, string $query, int $page, ?array $visible, callable $manifest): array
    {
        CommitData::validateQuery($sha, '', $page, 1000000);
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 200 || preg_match('/[\x00-\x1f\x7f]/', $query)) {
            throw Failure::invalid('invalid_search', 'Enter a file name or path of up to 200 characters.');
        }
        $tree = $manifest();
        $items = [];
        foreach ($tree['files'] as $path => $size) {
            $path = (string) $path;
            if ($visible !== null && !isset($visible[$path])) {
                continue;
            }
            if (mb_stripos($path, $query) !== false) {
                $items[] = ['name' => basename($path), 'path' => $path, 'type' => 'file', 'size' => $size];
            }
        }
        usort($items, static fn(array $a, array $b): int => strnatcasecmp($a['path'], $b['path']));
        return ['items' => array_slice($items, ($page - 1) * self::PAGE, self::PAGE), 'page' => $page,
            'nextPage' => count($items) > $page * self::PAGE ? $page + 1 : null, 'limited' => $tree['truncated']];
    }
}
