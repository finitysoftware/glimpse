<?php

declare(strict_types=1);

namespace Glimpse\Cache;

/** The last API quota a platform reported for one user's token; all NULL before the first request. */
final readonly class PlatformQuota
{
    public function __construct(
        public ?int $rateLimit,
        public ?int $rateRemaining,
        /** Unix time of the next reset. */
        public ?int $rateReset,
        public ?string $rateCheckedAt,
    ) {}
}
