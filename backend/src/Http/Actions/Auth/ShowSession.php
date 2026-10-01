<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Auth;

use Glimpse\Http\Input;
use Glimpse\Http\Responders\SessionResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/auth/session: the signed-in workspace session. */
final readonly class ShowSession
{
    public function __construct(private SessionResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->respond(Input::user($request));
    }
}
