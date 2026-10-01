<?php

declare(strict_types=1);

namespace Glimpse\Cache;

/** One response prepared for the preview cache: checked for size and encoding, with only the headers a later request needs. */
final readonly class CacheEntry
{
    public function __construct(
        public string $key,
        /** The repository (or catalogue) the entry belongs to, for invalidating it with that scope. */
        public string $scope,
        /** The owner's local repository row, so the entry is removed with it. */
        public ?int $repositoryId,
        public ?string $etag,
        public string $payload,
        public int $ttl,
    ) {}
}
