<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\SalesDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalesDashboardController extends Controller
{
    public function __construct(protected SalesDashboardService $dashboardService) {}

    public function __invoke(Request $request): Response
    {
        $data = $this->dashboardService->getDashboardData($request->user()?->id);

        return Inertia::render('Sales/Dashboard/Index', [
            'summary' => $data['summary'],
            'funnel' => $data['funnel'],
            'myOpportunities' => $data['my_opportunities'],
            'myQuotes' => $data['my_quotes'],
            'myOrders' => $data['my_orders'],
            'overdueInvoices' => $data['overdue_invoices'],
        ]);
    }
}
