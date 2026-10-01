<?php

declare(strict_types=1);

namespace Glimpse\Source;

/** How one upstream request is cached. */
final readonly class CacheRule
{
    /**
     * @param bool $content File, tree or commit data; marks the response source as cache or upstream.
     * @param bool $metadata Catalogue or repository metadata: keyed without the repository ID and reports when it was checked.
     * @param bool $immutable Addressed by commit SHA, so it may be kept for an hour and is never refreshed early.
     * @param bool $store False for intermediate pages that the client assembles and caches as one derived entry.
     */
    public function __construct(public bool $content, public bool $metadata, public bool $immutable, public bool $store = true) {}
}
