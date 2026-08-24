<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3N — the unified reporting hub. Links out to the four statutory reports built here, §3J's
 * Budget vs. Actual, plus the AR/AP aging engines already built in §3D/§3E — it does not
 * duplicate their screens. Inventory Valuation (Inventory module) isn't linked because it
 * isn't built yet; nothing to point at.
 */
class ReportingHubController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Accounting/Reports/Index');
    }
}
