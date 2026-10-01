<?php

declare(strict_types=1);

namespace Glimpse\Http;

use RuntimeException;

/**
 * A request this API does not accept at the protocol level (origin, content type, body size, host, maintenance),
 * rejected by Middleware\RequestPolicy before any route runs. Everything else fails with a Glimpse\Failure.
 */
final class HttpError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
