<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin;

use Glimpse\Auth\AdminAuth;
use Glimpse\Http\Cookies;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/admin/auth/password: changes the own password and continues in a renewed admin session. */
final readonly class ChangeAdminPassword
{
    public function __construct(private AdminAuth $auth, private JsonResponder $responder, private Cookies $cookies) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        [$current, $new] = Input::passwordChange($request);
        $result = $this->auth->changePassword(Input::admin($request), $current, $new, Input::address($request));
        return $this->responder->withCookie($result->context->session(), $this->cookies->set(Cookies::ADMIN, $result->token, AdminAuth::TTL_SECONDS));
    }
}
