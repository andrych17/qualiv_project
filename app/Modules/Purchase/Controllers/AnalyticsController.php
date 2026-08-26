<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Services\EsgComplianceService;
use App\Modules\Purchase\Services\SpendAnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function __construct(
        protected SpendAnalyticsService $spendService,
        protected EsgComplianceService $esgService,
    ) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('purchase.analytics.spend');
    }

    public function spend(Request $request): Response
    {
        $filters = $request->only([
            'date_range',
            'start_date',
            'end_date',
            'supplier_id',
            'category_id',
            'cost_center_id',
        ]);

        $analytics = $this->spendService->getSpendAnalytics($filters);

        return Inertia::render('Purchase/Analytics/Spend', $analytics);
    }

    public function esg(Request $request): Response
    {
        $filters = $request->only([
            'date_range',
            'start_date',
            'end_date',
            'supplier_id',
            'category_id',
        ]);

        $report = $this->esgService->getEsgComplianceReport($filters);

        return Inertia::render('Purchase/Analytics/Esg', $report);
    }
}
