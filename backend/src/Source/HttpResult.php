<?php

declare(strict_types=1);

namespace Glimpse\Source;

final readonly class HttpResult
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status, public array $headers, public string $body) {}
}
