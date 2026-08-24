<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\ControlReconciliationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3Q — AR/AP control reconciliation report (read-only, not a matching UI — see service docblock). */
class ControlReconciliationController extends Controller
{
    public function __construct(
        private readonly ControlReconciliationService $service,
        private readonly CompanyContextService $companyContext,
    ) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);
        $company = Company::query()->findOrFail($companyId);

        return Inertia::render('Accounting/ControlReconciliation/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'ar' => $this->service->arReport($company),
            'ap' => $this->service->apReport($company),
            'inventory' => $this->service->inventoryReport($company),
            'payroll' => $this->service->payrollReport($company),
        ]);
    }
}
