<?php

declare(strict_types=1);

namespace Glimpse\Http\Middleware;

use Glimpse\Auth\Csrf;
use Glimpse\Auth\UserAuth;
use Glimpse\Auth\UserContext;
use Glimpse\Failure;
use Glimpse\Http\Cookies;
use Glimpse\Http\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequireUser implements MiddlewareInterface
{
    public function __construct(private UserAuth $auth) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = Cookies::token($request, Cookies::WORKSPACE);
        $context = $this->auth->authenticate($token);
        if (!$context->can(UserContext::WORKSPACE)) {
            throw Failure::forbidden('forbidden', 'This account cannot use the workspace.');
        }
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && !Csrf::valid(UserAuth::AUDIENCE, $token, $request->getHeaderLine('X-CSRF-Token'))) {
            throw Failure::forbidden('csrf_failed', 'Request verification failed.');
        }
        // An administrator can require a new password: until then the account can only read its session, change the password or sign out.
        if ($context->mustChangePassword && !in_array($request->getUri()->getPath(), ['/api/auth/session', '/api/auth/password', '/api/auth/logout'], true)) {
            throw Failure::forbidden('password_change_required', 'Set a new password to continue.');
        }
        return $handler->handle($request->withAttribute(Input::USER, $context));
    }
}
