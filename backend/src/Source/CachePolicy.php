<?php

declare(strict_types=1);

namespace Glimpse\Source;

/** Classifies a platform's API paths for the preview cache. */
interface CachePolicy
{
    public function rule(string $path): CacheRule;
}
