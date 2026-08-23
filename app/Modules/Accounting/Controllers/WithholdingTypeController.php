<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\TaxBuktiPotong;
use App\Modules\Accounting\Models\WithholdingType;
use App\Modules\Accounting\Requests\StoreWithholdingTypeRequest;
use App\Modules\Accounting\Requests\UpdateWithholdingTypeRequest;
use App\Modules\Accounting\Services\WithholdingTypeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3M PPh withholding types — plain company-scoped CRUD. */
class WithholdingTypeController extends Controller
{
    public function __construct(private readonly WithholdingTypeService $service) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) ($request->integer('company_id') ?: $companies->first()?->id);

        $withholdingTypes = WithholdingType::query()->where('company_id', $companyId)->with('glPayableAccount:id,account_code,account_name')->orderBy('code')->get();

        return Inertia::render('Accounting/WithholdingTypes/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'withholdingTypes' => $withholdingTypes->map(fn (WithholdingType $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'bp_type' => $w->bp_type,
                'name' => $w->name,
                'rate' => (float) $w->rate,
                'is_final' => $w->is_final,
                'gl_account_label' => "{$w->glPayableAccount->account_code} {$w->glPayableAccount->account_name}",
                'is_active' => $w->is_active,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/WithholdingTypes/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            'accounts' => $this->accountOptions($companyId),
            'bpTypes' => TaxBuktiPotong::TYPES,
        ]);
    }

    public function store(StoreWithholdingTypeRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('accounting.withholding-types.index', ['company_id' => $request->input('company_id')])
            ->with('success', 'Withholding type created.');
    }

    public function edit(WithholdingType $withholdingType): Response
    {
        return Inertia::render('Accounting/WithholdingTypes/Edit', [
            'withholdingType' => $withholdingType->only(['id', 'company_id', 'code', 'bp_type', 'name', 'rate', 'is_final', 'gl_payable_account_id', 'is_active']),
            'accounts' => $this->accountOptions($withholdingType->company_id),
            'bpTypes' => TaxBuktiPotong::TYPES,
        ]);
    }

    public function update(UpdateWithholdingTypeRequest $request, WithholdingType $withholdingType)
    {
        $this->service->update($withholdingType, $request->validated());

        return redirect()->route('accounting.withholding-types.index', ['company_id' => $withholdingType->company_id])
            ->with('success', 'Withholding type updated.');
    }

    public function destroy(WithholdingType $withholdingType)
    {
        $companyId = $withholdingType->company_id;
        $this->service->delete($withholdingType);

        return redirect()->route('accounting.withholding-types.index', ['company_id' => $companyId])->with('success', 'Withholding type deleted.');
    }

    private function accountOptions(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return Account::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'account_name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} {$a->account_name}"])
            ->values()
            ->all();
    }
}
