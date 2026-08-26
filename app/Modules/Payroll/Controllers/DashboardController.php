<?php

namespace App\Modules\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Services\PayrollDashboardService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected PayrollDashboardService $dashboardService
    ) {}

    public function index(): Response
    {
        return Inertia::render('Payroll/Dashboard/Index', [
            'metrics' => $this->dashboardService->getMetrics(),
            'queues' => $this->dashboardService->getQueues(),
        ]);
    }
}
