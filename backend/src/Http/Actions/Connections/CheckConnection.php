<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Connections;

use Glimpse\Connections\ConnectionService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/workspace/connections/{github|gitlab}/probe: checks the saved token with its platform again. */
final readonly class CheckConnection
{
    public function __construct(private ConnectionService $connections, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->respond($this->connections->probe(Input::user($request)));
    }
}
