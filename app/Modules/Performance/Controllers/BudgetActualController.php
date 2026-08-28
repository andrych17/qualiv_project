<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Models\BudgetLine;
use App\Modules\Performance\Requests\StoreBudgetActualRequest;
use App\Modules\Performance\Services\BudgetActualService;

/** §3B — manual actual entry for a BudgetLine whose category isn't GL-mapped. */
class BudgetActualController extends Controller
{
    public function __construct(protected BudgetActualService $service) {}

    public function store(StoreBudgetActualRequest $request, BudgetLine $budgetLine)
    {
        $this->service->upsert($budgetLine, (float) $request->validated('actual_value'));

        return redirect()->route('performance.budgets.edit', $budgetLine->budget_id)->with('success', 'Actual recorded.');
    }
}
