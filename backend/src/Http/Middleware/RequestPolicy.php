<?php

declare(strict_types=1);

namespace Glimpse\Http\Middleware;

use Glimpse\Http\HttpError;
use Glimpse\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequestPolicy implements MiddlewareInterface
{
    public function __construct(private Settings $settings) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        if ($this->settings->maintenance && !in_array($address, $this->settings->maintenanceAllowIps, true)) {
            throw new HttpError(503, 'maintenance', 'Glimpse is being updated. Try again in a few minutes.', 300);
        }
        // Health carries no data, so hosting monitors may call it by any address.
        $origin = $path === '/api/health' ? $this->settings->origin : $this->origin($request);
        if ($this->settings->isShareAlias($origin) && $path !== '/api/health'
            && preg_match('#^/api/(?:shares/[^/]+/(?:exchange|session|browse)|visitor/logout)$#D', $path) !== 1) {
            throw new HttpError(403, 'host_rejected', 'This address opens shared links only. Sign in on the main Glimpse address.');
        }
        if (!in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            // The Origin must be the origin of this very request, not just any configured address.
            if ($request->getHeaderLine('Origin') !== $origin) {
                throw new HttpError(403, 'origin_rejected', 'Request origin is not allowed.');
            }
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new HttpError(415, 'unsupported_media_type', 'Send an application/json request.');
            }
            $limit = self::limit($request);
            $body = '';
            $stream = $request->getBody();
            // php://input may only report EOF on the read after its last data chunk.
            while (strlen($body) <= $limit && !$stream->eof()) {
                $chunk = $stream->read($limit + 1 - strlen($body));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            if (strlen($body) > $limit) {
                throw new HttpError(413, 'request_too_large', 'Request body is too large.');
            }
            try {
                $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpError(400, 'invalid_json', 'Request body must be valid JSON.');
            }
            if (!is_array($data) || !str_starts_with(ltrim($body), '{')) {
                throw new HttpError(400, 'invalid_json', 'Request body must be a JSON object.');
            }
            $request = $request->withParsedBody($data);
        }
        return $handler->handle($request);
    }

    /** Origin served at the request's Host; forwarded host headers are never trusted. */
    private function origin(ServerRequestInterface $request): string
    {
        // Slim rebuilds the Host header without its port, so the raw header from the web server is authoritative.
        $raw = $request->getServerParams()['HTTP_HOST'] ?? null;
        $host = trim(is_string($raw) ? $raw : $request->getHeaderLine('Host'));
        if ($host === '') {
            // Only in-process requests lack a Host: web servers reject HTTP/1.1 requests without one.
            return $this->settings->origin;
        }
        return $this->settings->originForHost($host)
            ?? throw new HttpError(421, 'host_rejected', 'This address does not serve Glimpse.');
    }

    /** Small bodies everywhere; only the bounded visibility operations list may use up to 32 KiB, on the routes of both platforms. */
    private static function limit(ServerRequestInterface $request): int
    {
        return $request->getMethod() === 'PUT'
            && preg_match('#^/api/workspace/(?:repositories/[^/]+/[^/]+|gitlab/projects/[1-9][0-9]{0,17})/visibility$#D', $request->getUri()->getPath()) === 1
            ? 32768 : 4096;
    }
}
