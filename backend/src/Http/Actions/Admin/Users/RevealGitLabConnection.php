<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Users;

use Glimpse\Admin\AdminUserService;
use Glimpse\GitLab\GitLabConnectionService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/admin/users/{id}/gitlab/token/reveal: an account's GitLab address and token, shown on request. */
final readonly class RevealGitLabConnection
{
    public function __construct(
        private AdminUserService $users,
        private GitLabConnectionService $gitlab,
        private JsonResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = Input::userId($request);
        $this->users->requireAccount($userId);
        $saved = $this->gitlab->reveal($userId);
        return $this->responder->respond(['token' => $saved['token'] ?? null, 'baseUrl' => $saved['baseUrl'] ?? null]);
    }
}
