<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Visitor;

use Glimpse\Fields;
use Glimpse\Http\Cookies;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Sharing\VisitorAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/shares/{publicId}/exchange: a visitor's first visit trades the secret from the link's URL fragment for a
 * visitor session. One visitor session can hold links of several owners.
 */
final readonly class OpenShare
{
    public function __construct(private VisitorAccess $visitors, private JsonResponder $responder, private Cookies $cookies) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $secret = Fields::text(Input::body($request), 'secret');
        $token = Cookies::token($request, Cookies::VISITOR);
        $visit = $this->visitors->exchange(Input::argument($request, 'publicId'), $secret, $token, Input::address($request));
        return $this->responder->withCookie(['csrfToken' => $visit['csrfToken']], $this->cookies->set(Cookies::VISITOR, $visit['token'], VisitorAccess::TTL_SECONDS));
    }
}
