<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Visitor;

use Glimpse\Auth\Csrf;
use Glimpse\Failure;
use Glimpse\Http\Cookies;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Sharing\VisitorAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/visitor/logout: ends the visitor session and every link it held, in all tabs. */
final readonly class EndVisit
{
    public function __construct(private VisitorAccess $visitors, private JsonResponder $responder, private Cookies $cookies) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $token = Cookies::token($request, Cookies::VISITOR);
        if (!Csrf::valid(VisitorAccess::AUDIENCE, $token, $request->getHeaderLine('X-CSRF-Token'))) {
            throw Failure::forbidden('csrf_rejected', 'Request verification failed.');
        }
        $this->visitors->logout($token);
        return $this->responder->withCookie(['status' => 'signed_out'], $this->cookies->clear(Cookies::VISITOR));
    }
}
