<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Repositories;

use Glimpse\Failure;
use Glimpse\Fields;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Repositories\RepositoryServices;
use Glimpse\Source\Provider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/workspace/repositories?provider=&page=: one catalogue page of the owner's repositories on one platform. */
final readonly class ListRepositories
{
    public function __construct(private RepositoryServices $repositories, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $provider = Provider::tryFrom(Fields::text($request->getQueryParams(), 'provider', 'github'));
        if ($provider === null) {
            throw Failure::invalid('invalid_input', 'Unknown platform.');
        }
        $repositories = $this->repositories->forUser(Input::user($request)->userId, $provider);
        return $this->responder->respond($repositories->catalogue(Input::page($request)));
    }
}
