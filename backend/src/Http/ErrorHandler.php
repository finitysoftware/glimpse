<?php

declare(strict_types=1);

namespace Glimpse\Http;

use Glimpse\Failure;
use Glimpse\FailureKind;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Throwable;

/**
 * Turns every failure into a JSON error. A Failure gets the status of its kind, an HttpError its own status,
 * and anything else stays generic.
 */
final readonly class ErrorHandler
{
    public function __construct(private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request, Throwable $error): ResponseInterface
    {
        $requestId = bin2hex(random_bytes(8));
        if ($error instanceof Failure) {
            return $this->responder->error(self::status($error->kind), $error->errorCode, $error->getMessage(), $requestId, $error->retryAfter);
        }
        if ($error instanceof HttpError) {
            return $this->responder->error($error->status, $error->errorCode, $error->getMessage(), $requestId, $error->retryAfter);
        }
        if ($error instanceof HttpException) {
            $status = $error->getCode();
            return $status === 404
                ? $this->responder->error($status, 'not_found', 'API route not found.', $requestId)
                : $this->responder->error($status, 'method_not_allowed', 'Request method is not allowed.', $requestId);
        }
        // Never log exception messages, URLs, headers, credentials, or upstream response bodies; the throw site is enough to find the cause.
        error_log('Glimpse request=' . $requestId . ' failure=' . get_debug_type($error) . ' at=' . self::site($error));
        return $this->responder->error(503, 'service_unavailable', 'The service is temporarily unavailable.', $requestId);
    }

    /** Where an unexpected error was thrown, relative to the backend folder, e.g. src/Sharing/ShareLinks.php:84. */
    private static function site(Throwable $error): string
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
        $file = str_replace('\\', '/', $error->getFile());
        return (str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file)) . ':' . $error->getLine();
    }

    public static function status(FailureKind $kind): int
    {
        return match ($kind) {
            FailureKind::Invalid => 400,
            FailureKind::Unauthenticated => 401,
            FailureKind::Forbidden => 403,
            FailureKind::NotFound => 404,
            FailureKind::Conflict => 409,
            FailureKind::Rejected => 422,
            FailureKind::RateLimited => 429,
            FailureKind::Upstream => 502,
            FailureKind::Unavailable => 503,
        };
    }
}
