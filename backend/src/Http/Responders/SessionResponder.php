<?php

declare(strict_types=1);

namespace Glimpse\Http\Responders;

use Glimpse\Auth\UserAuth;
use Glimpse\Auth\UserContext;
use Glimpse\GitHub\GitHubConnectionService;
use Glimpse\GitLab\GitLabConnectionService;
use Glimpse\Http\Cookies;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/** The signed-in workspace session as the frontend keeps it: the account, its CSRF token and both platform connections. */
final readonly class SessionResponder
{
    public function __construct(
        private JsonResponder $json,
        private GitHubConnectionService $github,
        private GitLabConnectionService $gitlab,
        private Cookies $cookies,
    ) {}

    /** With $token, the response also sets the session cookie (after a sign-in or a password change). */
    public function respond(UserContext $user, #[SensitiveParameter] ?string $token = null): ResponseInterface
    {
        $session = [
            'user' => ['id' => $user->userId, 'username' => $user->username],
            'csrfToken' => $user->csrfToken,
            'github' => $this->github->summary($user->userId),
            'gitlab' => $this->gitlab->summary($user->userId),
            'passwordChangeRequired' => $user->mustChangePassword,
        ];
        return $token === null
            ? $this->json->respond($session)
            : $this->json->withCookie($session, $this->cookies->set(Cookies::WORKSPACE, $token, UserAuth::TTL_SECONDS));
    }
}
