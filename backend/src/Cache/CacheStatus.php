<?php

declare(strict_types=1);

namespace Glimpse\Cache;

/** One workspace's preview cache over all platforms, with the quota of each platform's token; never installation totals. */
final readonly class CacheStatus
{
    /** @param array<string, PlatformQuota> $providers Keyed by platform. */
    public function __construct(
        public int $entries,
        public int $bytes,
        public int $maxBytes,
        public int $maxEntries,
        public int $hits,
        public int $misses,
        public int $upstreamCalls,
        public int $revalidations,
        public int $evictions,
        public array $providers,
    ) {}
}
