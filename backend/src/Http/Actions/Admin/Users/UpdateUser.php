<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Users;

use Glimpse\Admin\AccountChanges;
use Glimpse\Admin\AdminUserService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** PATCH /api/admin/users/{id}: changes an account's details, role, status or password; expectedVersion guards concurrent edits. */
final readonly class UpdateUser
{
    public function __construct(private AdminUserService $users, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->users->update(Input::admin($request), Input::userId($request), AccountChanges::fromBody(Input::body($request)));
        return $this->responder->respond($user);
    }
}
