<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Repositories;

use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Visibility\RepositoryVisibilityService;
use Glimpse\Visibility\VisibilityChange;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** PUT …/visibility: saves the owner's changes to one branch's file visibility; it applies to links created afterwards. */
final readonly class SaveVisibility
{
    public function __construct(private RepositoryVisibilityService $visibility, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $ref = Input::repository($request);
        $userId = Input::user($request)->userId;
        return $this->responder->respond($this->visibility->save($userId, $ref, VisibilityChange::fromBody(Input::body($request))));
    }
}
