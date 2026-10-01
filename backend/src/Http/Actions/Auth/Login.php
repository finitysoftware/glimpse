<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Auth;

use Glimpse\Auth\UserAuth;
use Glimpse\Http\Cookies;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\SessionResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/auth/login: signs in to the workspace, replacing any workspace session this browser already had. */
final readonly class Login
{
    public function __construct(private UserAuth $auth, private SessionResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        [$username, $password] = Input::credentials($request);
        $result = $this->auth->login($username, $password, Input::address($request), Cookies::token($request, Cookies::WORKSPACE));
        return $this->responder->respond($result->context, $result->token);
    }
}
