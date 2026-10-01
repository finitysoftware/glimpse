<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Shares;

use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Sharing\LinkPermissions;
use Glimpse\Sharing\ShareLinks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/workspace/shares/{publicId}/permissions: changes a link's branches, History and file visibility; its URL stays. */
final readonly class UpdateSharePermissions
{
    public function __construct(private ShareLinks $shares, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $permissions = LinkPermissions::fromBody(Input::body($request));
        $this->shares->permissions(Input::user($request)->userId, Input::argument($request, 'publicId'), $permissions);
        return $this->responder->respond(['status' => 'updated']);
    }
}
