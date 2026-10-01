<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Connections;

use Glimpse\Connections\TokenChange;
use Glimpse\GitLab\GitLabConnectionService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/workspace/connections/gitlab: verifies a GitLab address and token together and saves them. expectedVersion
 * is the connection version the owner saw, so a change made in another window is not overwritten.
 */
final readonly class SaveGitLabConnection
{
    public function __construct(private GitLabConnectionService $connections, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $change = TokenChange::gitlab(Input::body($request));
        return $this->responder->respond(
            $this->connections->save(Input::user($request), $change->baseUrl, $change->token, $change->expectedVersion, Input::address($request)),
        );
    }
}
