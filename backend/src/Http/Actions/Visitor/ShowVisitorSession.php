<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Visitor;

use Glimpse\Auth\Csrf;
use Glimpse\Http\Cookies;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Sharing\VisitorAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/shares/{publicId}/session: a returning visitor's session for this link, when it still holds it. */
final readonly class ShowVisitorSession
{
    public function __construct(private VisitorAccess $visitors, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $token = Cookies::token($request, Cookies::VISITOR);
        $this->visitors->authorize(Input::argument($request, 'publicId'), $token);
        return $this->responder->respond(['csrfToken' => Csrf::token(VisitorAccess::AUDIENCE, $token)]);
    }
}
