<?php

declare(strict_types=1);

namespace Glimpse\Http\Middleware;

use Glimpse\Auth\AdminAuth;
use Glimpse\Auth\Csrf;
use Glimpse\Failure;
use Glimpse\Http\Cookies;
use Glimpse\Http\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Admin API routes: a valid admin session and, for changes, its own CSRF token (a workspace token never validates here). */
final readonly class RequireAdmin implements MiddlewareInterface
{
    public function __construct(private AdminAuth $auth) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = Cookies::token($request, Cookies::ADMIN);
        $context = $this->auth->authenticate($token);
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && !Csrf::valid(AdminAuth::AUDIENCE, $token, $request->getHeaderLine('X-CSRF-Token'))) {
            throw Failure::forbidden('csrf_failed', 'Request verification failed.');
        }
        if ($context->mustChangePassword && !in_array($request->getUri()->getPath(), ['/api/admin/auth/session', '/api/admin/auth/password', '/api/admin/auth/logout'], true)) {
            throw Failure::forbidden('password_change_required', 'Set a new password to continue.');
        }
        return $handler->handle($request->withAttribute(Input::ADMIN, $context));
    }
}
