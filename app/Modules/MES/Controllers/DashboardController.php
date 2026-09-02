<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Services\MesDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3T — Dashboards. Three focused read models; see MesDashboardService for the Plant/Line/Process Area split. */
class DashboardController extends Controller
{
    public function __construct(protected MesDashboardService $service) {}

    public function plant(Request $request): Response
    {
        return Inertia::render('MES/Dashboards/Plant', $this->service->plant($request->input('date') ?: now()->toDateString()));
    }

    public function line(Request $request): Response
    {
        $date = $request->input('date') ?: now()->toDateString();

        return Inertia::render('MES/Dashboards/Line', ['date' => $date, 'lines' => $this->service->lines($date)]);
    }

    public function processArea(Request $request): Response
    {
        return Inertia::render('MES/Dashboards/ProcessArea', $this->service->processArea($request->input('date') ?: now()->toDateString()));
    }
}
