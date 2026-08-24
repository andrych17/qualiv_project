<?php

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\BudgetVsActualService;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3J — Budget vs. Actual report. Each row links into AccountLedgerController's period+cost-center drill-down rather than duplicating its query. */
class BudgetVsActualController extends BaseReportController
{
    public function __construct(private readonly BudgetVsActualService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);
        $company = Company::query()->findOrFail($companyId);

        $fiscalYears = FiscalYear::query()->where('company_id', $companyId)->orderByDesc('year')->get(['id', 'year']);
        $fiscalYearId = $request->integer('fiscal_year_id') ?: $fiscalYears->first()?->id;

        $costCenters = CostCenter::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $costCenterId = $request->filled('cost_center_id') ? $request->integer('cost_center_id') : null;

        $rows = $fiscalYearId
            ? $this->service->report($company, FiscalYear::query()->findOrFail($fiscalYearId), $costCenterId)
            : [];

        return Inertia::render('Accounting/Reports/BudgetVsActual', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'fiscalYears' => $fiscalYears->map(fn (FiscalYear $y) => ['value' => $y->id, 'label' => (string) $y->year]),
            'selectedFiscalYearId' => $fiscalYearId,
            'costCenters' => $costCenters->map(fn (CostCenter $c) => ['value' => $c->id, 'label' => "{$c->code} {$c->name}"]),
            'selectedCostCenterId' => $costCenterId,
            'rows' => $rows,
        ]);
    }
}
