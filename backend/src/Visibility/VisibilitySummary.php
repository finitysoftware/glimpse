<?php

declare(strict_types=1);

namespace Glimpse\Visibility;

/** The saved profile of one branch without the Git tree: what a new link of the branch would copy, with its first selected paths. */
final readonly class VisibilitySummary
{
    /** @param list<string> $preview */
    public function __construct(
        public string $branch,
        /** 'all' or 'selected'; NULL when nothing is saved. */
        public ?string $mode,
        public int $version,
        public int $selectedCount,
        public array $preview,
    ) {}
}
