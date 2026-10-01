<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Shares;

use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Sharing\ShareLinks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/workspace/shares/{publicId}/delete: removes a revoked link from the list. */
final readonly class DeleteShare
{
    public function __construct(private ShareLinks $shares, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->shares->delete(Input::user($request)->userId, Input::argument($request, 'publicId'));
        return $this->responder->respond(['status' => 'deleted']);
    }
}
