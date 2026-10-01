<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Repositories;

use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Repositories\BrowseQuery;
use Glimpse\Repositories\RepositoryServices;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET …/browse: the owner's own view of a repository (a folder, a file, search, History, a commit or the branches),
 * with no link restrictions.
 */
final readonly class BrowseRepository
{
    public function __construct(private RepositoryServices $repositories, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $ref = Input::repository($request);
        $repositories = $this->repositories->forUser(Input::user($request)->userId, $ref->provider);
        return $this->responder->respond($repositories->browse($ref, BrowseQuery::fromQuery($request->getQueryParams())));
    }
}
