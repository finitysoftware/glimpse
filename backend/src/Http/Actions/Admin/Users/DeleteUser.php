<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Users;

use Glimpse\Admin\AccountDeletion;
use Glimpse\Admin\AdminUserService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/admin/users/{id}/delete: permanently removes an account with its connections, repositories and links. */
final readonly class DeleteUser
{
    public function __construct(private AdminUserService $users, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $deletion = AccountDeletion::fromBody(Input::body($request));
        $this->users->delete(Input::admin($request), Input::userId($request), $deletion->expectedVersion);
        return $this->responder->respond(['status' => 'deleted']);
    }
}
