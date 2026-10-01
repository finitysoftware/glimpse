<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin;

use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/admin/auth/session: the signed-in admin session. */
final readonly class ShowAdminSession
{
    public function __construct(private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->respond(Input::admin($request)->session());
    }
}
