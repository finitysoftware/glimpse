<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin;

use Glimpse\Auth\AdminAuth;
use Glimpse\Http\Cookies;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/admin/auth/logout: ends the admin session and clears its cookie. */
final readonly class AdminLogout
{
    public function __construct(private AdminAuth $auth, private JsonResponder $responder, private Cookies $cookies) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->logout(Input::admin($request));
        return $this->responder->withCookie(['status' => 'signed_out'], $this->cookies->clear(Cookies::ADMIN));
    }
}
