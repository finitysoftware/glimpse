<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Auth;

use Glimpse\Auth\UserAuth;
use Glimpse\Http\Cookies;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/auth/logout: ends the workspace session and clears its cookie. */
final readonly class Logout
{
    public function __construct(private UserAuth $auth, private JsonResponder $responder, private Cookies $cookies) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->logout(Input::user($request));
        return $this->responder->withCookie(['status' => 'signed_out'], $this->cookies->clear(Cookies::WORKSPACE));
    }
}
