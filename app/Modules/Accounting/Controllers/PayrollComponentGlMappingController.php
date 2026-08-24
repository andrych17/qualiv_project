<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\PayrollComponentGlMapping;
use App\Modules\Accounting\Requests\StorePayrollComponentGlMappingRequest;
use App\Modules\Accounting\Requests\UpdatePayrollComponentGlMappingRequest;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\PayrollComponentGlMappingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3S — component_code → GL account mapping CRUD. */
class PayrollComponentGlMappingController extends Controller
{
    public function __construct(private readonly PayrollComponentGlMappingService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $mappings = PayrollComponentGlMapping::query()
            ->where('company_id', $companyId)
            ->with(['glAccount:id,account_code,account_name', 'payableAccount:id,account_code,account_name'])
            ->orderBy('component_code')
            ->get();

        return Inertia::render('Accounting/PayrollComponentGlMappings/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'mappings' => $mappings->map(fn (PayrollComponentGlMapping $m) => [
                'id' => $m->id,
                'component_code' => $m->component_code,
                'component_label' => $m->component_label,
                'component_type' => $m->component_type,
                'gl_account' => "{$m->glAccount->account_code} — {$m->glAccount->account_name}",
                'payable_account' => $m->payableAccount ? "{$m->payableAccount->account_code} — {$m->payableAccount->account_name}" : null,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/PayrollComponentGlMappings/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            'accounts' => $this->accountOptions($companyId),
        ]);
    }

    public function store(StorePayrollComponentGlMappingRequest $request)
    {
        $mapping = $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('accounting.payroll-component-gl-mappings.index', ['company_id' => $mapping->company_id])->with('success', 'Mapping saved.');
    }

    public function edit(PayrollComponentGlMapping $mapping): Response
    {
        return Inertia::render('Accounting/PayrollComponentGlMappings/Edit', [
            'mapping' => $mapping->only(['id', 'company_id', 'component_code', 'component_label', 'component_type', 'gl_account_id', 'payable_account_id']),
            'accounts' => $this->accountOptions($mapping->company_id),
        ]);
    }

    public function update(UpdatePayrollComponentGlMappingRequest $request, PayrollComponentGlMapping $mapping)
    {
        $this->service->update($mapping, $request->validated(), $request->user()->id);

        return redirect()->route('accounting.payroll-component-gl-mappings.index', ['company_id' => $mapping->company_id])->with('success', 'Mapping updated.');
    }

    public function destroy(Request $request, PayrollComponentGlMapping $mapping)
    {
        $companyId = $mapping->company_id;
        $this->service->delete($mapping, $request->user()->id);

        return redirect()->route('accounting.payroll-component-gl-mappings.index', ['company_id' => $companyId])->with('success', 'Mapping deleted.');
    }

    private function accountOptions(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return Account::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('account_code')
            ->get(['id', 'account_code', 'account_name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} — {$a->account_name}"])
            ->all();
    }
}
