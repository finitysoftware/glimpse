<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Shares;

use Glimpse\Admin\AdminShareService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/admin/shares/{publicId}/reveal: the URL of any owner's active link. */
final readonly class RevealAnyShare
{
    public function __construct(private AdminShareService $shares, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->respond($this->shares->reveal(Input::argument($request, 'publicId')));
    }
}
