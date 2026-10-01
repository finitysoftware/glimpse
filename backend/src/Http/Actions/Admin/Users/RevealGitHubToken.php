<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Users;

use Glimpse\Admin\AdminUserService;
use Glimpse\GitHub\GitHubConnectionService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/admin/users/{id}/github/token/reveal: an account's saved GitHub token, shown on request. */
final readonly class RevealGitHubToken
{
    public function __construct(
        private AdminUserService $users,
        private GitHubConnectionService $github,
        private JsonResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = Input::userId($request);
        $this->users->requireAccount($userId);
        return $this->responder->respond(['token' => $this->github->reveal($userId)]);
    }
}
