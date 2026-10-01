<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Shares;

use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Sharing\NewShare;
use Glimpse\Sharing\ShareLinks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST …/shares: creates an access link with its branches, History and file visibility; the URL is in the response. */
final readonly class CreateShare
{
    public function __construct(private ShareLinks $shares, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $link = $this->shares->create(Input::user($request)->userId, Input::repository($request), NewShare::fromBody(Input::body($request)));
        return $this->responder->respond($link, 201);
    }
}
