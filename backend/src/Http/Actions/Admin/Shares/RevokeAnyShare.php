<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Shares;

use Glimpse\Admin\AdminShareService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/admin/shares/{publicId}/revoke: stops any owner's link, with the same effect as the owner's Revoke. */
final readonly class RevokeAnyShare
{
    public function __construct(private AdminShareService $shares, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->shares->revoke(Input::argument($request, 'publicId'));
        return $this->responder->respond(['status' => 'revoked']);
    }
}
