<?php

declare(strict_types=1);

namespace Glimpse\Source;

interface Transport
{
    public function get(string $path, int $timeoutMilliseconds, ?string $etag = null): HttpResult;
}
