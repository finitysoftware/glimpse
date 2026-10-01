<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Auth;

use Glimpse\Auth\UserAuth;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\SessionResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/auth/password: changes the own password and continues in a renewed session; other devices are signed out. */
final readonly class ChangePassword
{
    public function __construct(private UserAuth $auth, private SessionResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        [$current, $new] = Input::passwordChange($request);
        $result = $this->auth->changePassword(Input::user($request), $current, $new, Input::address($request));
        return $this->responder->respond($result->context, $result->token);
    }
}
