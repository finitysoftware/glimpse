<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions;

use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;

/** GET /api/health: answers without touching the database, for uptime checks. */
final readonly class Health
{
    public function __construct(private JsonResponder $responder) {}

    public function __invoke(): ResponseInterface
    {
        return $this->responder->respond(['status' => 'ok']);
    }
}
