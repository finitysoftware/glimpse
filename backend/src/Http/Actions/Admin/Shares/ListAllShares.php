<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin\Shares;

use Glimpse\Admin\AdminShareService;
use Glimpse\Admin\LinkFilter;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/admin/shares: one page of every account's access links, filtered by owner, status and search. */
final readonly class ListAllShares
{
    public function __construct(private AdminShareService $shares, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->respond($this->shares->list(LinkFilter::fromQuery($request->getQueryParams())));
    }
}
