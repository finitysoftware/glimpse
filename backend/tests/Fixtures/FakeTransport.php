<?php

declare(strict_types=1);

namespace Glimpse\Tests\Fixtures;

use Glimpse\Source\HttpResult;
use Glimpse\Source\Transport;
use RuntimeException;

final class FakeTransport implements Transport
{
    /** @var list<string> */
    public array $paths = [];
    /** @var list<int> */
    public array $timeouts = [];
    /** @var list<string|null> */
    public array $etags = [];

    /** @param list<HttpResult|\Throwable> $results */
    public function __construct(private array $results) {}

    public function get(string $path, int $timeoutMilliseconds, ?string $etag = null): HttpResult
    {
        $this->paths[] = $path;
        $this->timeouts[] = $timeoutMilliseconds;
        $this->etags[] = $etag;
        $result = array_shift($this->results) ?? throw new RuntimeException('Unexpected HTTP call.');
        if ($result instanceof \Throwable) {
            throw $result;
        }
        return $result;
    }
}
