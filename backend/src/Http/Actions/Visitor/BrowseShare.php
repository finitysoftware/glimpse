<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Visitor;

use Glimpse\Http\Cookies;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Repositories\BrowseQuery;
use Glimpse\Sharing\VisitorAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/shares/{publicId}/browse: a visitor's view of the shared repository (see VisitorAccess::browse). */
final readonly class BrowseShare
{
    public function __construct(private VisitorAccess $visitors, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = BrowseQuery::fromQuery($request->getQueryParams());
        return $this->responder->respond($this->visitors->browse(Input::argument($request, 'publicId'), Cookies::token($request, Cookies::VISITOR), $query));
    }
}
