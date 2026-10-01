<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

/** One page of a repository's links, whether the repository is shared at all, and whether URLs can be stored. */
final readonly class ShareListing
{
    /** @param list<ShareLink> $items */
    public function __construct(
        public array $items,
        public bool $active,
        public ?int $nextPage,
        public bool $storageConfigured,
    ) {}
}
