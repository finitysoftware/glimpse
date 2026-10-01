<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Repositories;

use Glimpse\Fields;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Visibility\RepositoryVisibilityService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET …/visibility?branch=: the saved file visibility of one branch. With summary=1 it is what a new link would
 * copy; otherwise it adds the branch's file list for editing.
 */
final readonly class ShowVisibility
{
    public function __construct(private RepositoryVisibilityService $visibility, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $ref = Input::repository($request);
        $userId = Input::user($request)->userId;
        $branch = Fields::text($query, 'branch');
        return $this->responder->respond(Fields::text($query, 'summary', '0') === '1'
            ? $this->visibility->summary($userId, $ref, $branch)
            : $this->visibility->profile($userId, $ref, $branch));
    }
}
