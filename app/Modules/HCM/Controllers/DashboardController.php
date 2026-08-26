<?php

namespace App\Modules\HCM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Services\HcmDashboardService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected HcmDashboardService $dashboardService,
    ) {}

    public function __invoke(): Response
    {
        return Inertia::render('HCM/Dashboard/Index', [
            'metrics' => $this->dashboardService->getMetrics(),
            'queues' => $this->dashboardService->getDashboardQueues(),
        ]);
    }
}
