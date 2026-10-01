<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Repositories;

use Glimpse\Http\Input;
use Glimpse\Http\Responders\JsonResponder;
use Glimpse\Sharing\RepositorySharing;
use Glimpse\Sharing\SharingSwitch;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST …/availability: turns sharing of a repository on or off for all its links. */
final readonly class SetSharing
{
    public function __construct(private RepositorySharing $sharing, private JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $switch = SharingSwitch::fromBody(Input::body($request));
        $this->sharing->setActive(Input::user($request)->userId, Input::repository($request), $switch->active);
        return $this->responder->respond(['active' => $switch->active]);
    }
}
