<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Users;

use Glimpse\Admin\AdminUserService;
use Glimpse\Connections\ConnectionService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/admin/users/{id}/github/token/remove and …/gitlab/remove: removes an account's connection to one platform;
 * its links of that platform stop working.
 */
final readonly class RemoveConnection
{
    public function __construct(
        private AdminUserService $users,
        private ConnectionService $connections,
        private JsonResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = Input::userId($request);
        $this->users->requireAccount($userId);
        $this->connections->remove($userId);
        return $this->responder->respond($this->users->detail($userId));
    }
}
