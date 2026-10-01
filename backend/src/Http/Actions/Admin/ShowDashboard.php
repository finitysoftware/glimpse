<?php

declare(strict_types=1);

namespace Glimpse\Http\Actions\Admin;

use Glimpse\Admin\AdminDashboardService;
use Glimpse\Http\Responders\JsonResponder;
use Psr\Http\Message\ResponseInterface;

/** GET /api/admin/dashboard: platform totals, the last 14 days and the configuration overview. */
final readonly class ShowDashboard
{
    public function __construct(private AdminDashboardService $dashboard, private JsonResponder $responder) {}

    public function __invoke(): ResponseInterface
    {
        return $this->responder->respond($this->dashboard->overview());
    }
}
