<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PP\Services\DashboardService;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3O — Production Planning Dashboard. Pure read model; see DashboardService for the aggregation. */
class DashboardController extends Controller
{
    public function __construct(protected DashboardService $service) {}

    public function index(): Response
    {
        return Inertia::render('PP/Dashboard', $this->service->summary());
    }
}
