<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Users;

use Glimpse\Admin\AdminUserService;
use Glimpse\Connections\TokenChange;
use Glimpse\GitHub\GitHubConnectionService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** PUT /api/admin/users/{id}/github/token: verifies a new GitHub token with GitHub and saves it for the account. */
final readonly class ReplaceGitHubToken
{
    public function __construct(
        private AdminUserService $users,
        private GitHubConnectionService $github,
        private JsonResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $change = TokenChange::github(Input::body($request));
        $userId = Input::userId($request);
        $this->users->requireAccount($userId);
        $this->github->replaceFor($userId, $change->token, $change->expectedVersion, Input::address($request));
        return $this->responder->respond($this->users->detail($userId));
    }
}
