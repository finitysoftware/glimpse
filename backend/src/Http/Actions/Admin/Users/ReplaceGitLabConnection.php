<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Users;

use Glimpse\Admin\AdminUserService;
use Glimpse\Connections\TokenChange;
use Glimpse\GitLab\GitLabConnectionService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** PUT /api/admin/users/{id}/gitlab: verifies a GitLab address and token together and saves them for the account. */
final readonly class ReplaceGitLabConnection
{
    public function __construct(
        private AdminUserService $users,
        private GitLabConnectionService $gitlab,
        private JsonResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $change = TokenChange::gitlab(Input::body($request));
        $userId = Input::userId($request);
        $this->users->requireAccount($userId);
        $this->gitlab->replaceFor($userId, $change->baseUrl, $change->token, $change->expectedVersion, Input::address($request));
        return $this->responder->respond($this->users->detail($userId));
    }
}
