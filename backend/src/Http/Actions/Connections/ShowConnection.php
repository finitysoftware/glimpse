<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Connections;

use Glimpse\Connections\ConnectionService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/workspace/connections/{github|gitlab}: the saved connection to one platform, with the token masked. */
final readonly class ShowConnection
{
    public function __construct(private ConnectionService $connections, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->respond($this->connections->status(Input::user($request)->userId));
    }
}
