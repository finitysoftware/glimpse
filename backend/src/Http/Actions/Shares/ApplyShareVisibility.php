<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Shares;

use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Sharing\ShareLinks;
use Glimpse\Sharing\VisibilityUpdate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/workspace/shares/{publicId}/visibility: applies the current saved file visibility of some branches to a
 * link; its branches, History and URL stay.
 */
final readonly class ApplyShareVisibility
{
    public function __construct(private ShareLinks $shares, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = Input::user($request)->userId;
        $this->shares->applyVisibility($userId, Input::argument($request, 'publicId'), VisibilityUpdate::fromBody(Input::body($request)));
        return $this->responder->respond(['status' => 'updated']);
    }
}
