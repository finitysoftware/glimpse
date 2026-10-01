<?php

declare(strict_types=1);

namespace Glimpse;

use RuntimeException;

/**
 * A failure the user is told about: its kind, a stable code for the frontend and a message that is safe to show.
 * The domain never picks HTTP statuses; Http\ErrorHandler does that from the kind.
 */
final class Failure extends RuntimeException
{
    public function __construct(
        public readonly FailureKind $kind,
        public readonly string $errorCode,
        string $message,
        /** Seconds until a retry can succeed, for rate limits and busy checks. */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $code, string $message): self
    {
        return new self(FailureKind::Invalid, $code, $message);
    }

    public static function unauthenticated(string $code, string $message): self
    {
        return new self(FailureKind::Unauthenticated, $code, $message);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self(FailureKind::Forbidden, $code, $message);
    }

    public static function notFound(string $code, string $message): self
    {
        return new self(FailureKind::NotFound, $code, $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(FailureKind::Conflict, $code, $message);
    }

    public static function rejected(string $code, string $message): self
    {
        return new self(FailureKind::Rejected, $code, $message);
    }

    public static function rateLimited(string $code, string $message, ?int $retryAfter = null): self
    {
        return new self(FailureKind::RateLimited, $code, $message, $retryAfter);
    }

    public static function upstream(string $code, string $message, ?int $retryAfter = null): self
    {
        return new self(FailureKind::Upstream, $code, $message, $retryAfter);
    }

    public static function unavailable(string $code, string $message, ?int $retryAfter = null): self
    {
        return new self(FailureKind::Unavailable, $code, $message, $retryAfter);
    }
}
