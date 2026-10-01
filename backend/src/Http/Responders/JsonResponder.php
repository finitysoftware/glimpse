<?php

declare(strict_types=1);

namespace Glimpse\Http\Responders;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/** Builds every API response: the result as a JSON body, optionally with a session cookie, or an error. */
final readonly class JsonResponder
{
    /** @param array<string, mixed>|object $data A result object is encoded with its public properties. */
    public function respond(array|object $data, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * A result that also sets, or clears, the cookie of a session.
     *
     * @param array<string, mixed>|object $data
     */
    public function withCookie(array|object $data, string $cookie): ResponseInterface
    {
        return $this->respond($data)->withHeader('Set-Cookie', $cookie);
    }

    /** An error as the frontend reads it: a code, a message for people and the request ID for support. */
    public function error(int $status, string $code, string $message, string $requestId, ?int $retryAfter = null): ResponseInterface
    {
        $response = $this->respond(['error' => ['code' => $code, 'message' => $message, 'requestId' => $requestId]], $status);
        return $retryAfter === null ? $response : $response->withHeader('Retry-After', (string) $retryAfter);
    }
}
