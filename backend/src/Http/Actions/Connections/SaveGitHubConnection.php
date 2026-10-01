<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Connections;

use Glimpse\Connections\TokenChange;
use Glimpse\GitHub\GitHubConnectionService;
use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/workspace/connections/github: verifies a new token with GitHub and saves it. expectedVersion is the
 * connection version the owner saw, so a change made in another window is not overwritten.
 */
final readonly class SaveGitHubConnection
{
    public function __construct(private GitHubConnectionService $connections, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $change = TokenChange::github(Input::body($request));
        return $this->responder->respond(
            $this->connections->save(Input::user($request), $change->token, $change->expectedVersion, Input::address($request)),
        );
    }
}
