<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

/** The file rule of one branch of a link, as the link lists show it. */
final readonly class BranchPolicy
{
    public function __construct(
        public string $branch,
        /** 'all' or 'selected'. */
        public string $mode,
        public int $selectedCount,
        /** The version of the saved profile the rule was copied from; NULL for all files. */
        public ?int $profileVersion,
    ) {}
}
