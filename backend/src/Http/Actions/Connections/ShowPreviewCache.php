<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Connections;

use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Repositories\RepositoryServices;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/workspace/preview-cache: the owner's preview cache and each platform's API quota. */
final readonly class ShowPreviewCache
{
    public function __construct(private RepositoryServices $repositories, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->respond($this->repositories->cacheStatus(Input::user($request)->userId));
    }
}
