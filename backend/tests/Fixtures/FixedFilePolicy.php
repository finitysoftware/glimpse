<?php

declare(strict_types=1);

namespace Glimpse\Tests\Fixtures;

use Glimpse\Repositories\FilePolicy;

/** File rules of a link in memory: the visible paths per branch, or NULL for every file of that branch. */
final readonly class FixedFilePolicy implements FilePolicy
{
    /** @param array<string, list<string>|null> $branches */
    public function __construct(private array $branches, private int $version = 1) {}

    public function paths(string $branch): ?array
    {
        $paths = $this->branches[$branch] ?? null;
        return $paths === null ? null : array_fill_keys($paths, true);
    }

    public function version(): int
    {
        return $this->version;
    }
}
