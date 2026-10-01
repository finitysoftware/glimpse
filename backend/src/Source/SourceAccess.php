<?php

declare(strict_types=1);

namespace Glimpse\Source;

/** One user's credential on one platform for a request: the owner of the cache entries it fills and the transport that carries its token. */
final readonly class SourceAccess
{
    public function __construct(
        public Provider $provider,
        public int $userId,
        public int $credentialVersion,
        public Transport $transport,
        public string $instance,
        /** How long this platform's responses may be kept in the preview cache. */
        public CachePolicy $cachePolicy,
    ) {}
}
