<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin;

use Glimpse\Auth\AdminAuth;
use Glimpse\Http\Cookies;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/admin/auth/login: signs in to the admin panel; only active accounts with the Admin role get in. */
final readonly class AdminLogin
{
    public function __construct(private AdminAuth $auth, private JsonResponder $responder, private Cookies $cookies) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        [$username, $password] = Input::credentials($request);
        $result = $this->auth->login($username, $password, Input::address($request), Cookies::token($request, Cookies::ADMIN));
        return $this->responder->withCookie($result->context->session(), $this->cookies->set(Cookies::ADMIN, $result->token, AdminAuth::TTL_SECONDS));
    }
}
