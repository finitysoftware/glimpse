<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Users;

use Glimpse\Admin\AdminUserService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/admin/users/{id}/sessions/revoke: signs an account out everywhere; its access links keep working. */
final readonly class RevokeUserSessions
{
    public function __construct(private AdminUserService $users, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->respond($this->users->revokeSessions(Input::userId($request)));
    }
}
