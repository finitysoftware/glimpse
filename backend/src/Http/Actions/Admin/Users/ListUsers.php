<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Users;

use Glimpse\Admin\AdminUserService;
use Glimpse\Admin\UserFilter;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/admin/users: one page of accounts, filtered and sorted by the query. */
final readonly class ListUsers
{
    public function __construct(private AdminUserService $users, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->respond($this->users->list(UserFilter::fromQuery($request->getQueryParams())));
    }
}
