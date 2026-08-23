<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Requests\StoreTaxCodeRequest;
use App\Modules\Accounting\Requests\UpdateTaxCodeRequest;
use App\Modules\Accounting\Services\TaxCodeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3M PPN tax codes — plain company-scoped CRUD, same list-selector convention as Accounts/CostCenters. */
class TaxCodeController extends Controller
{
    public function __construct(private readonly TaxCodeService $service) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) ($request->integer('company_id') ?: $companies->first()?->id);

        $taxCodes = TaxCode::query()->where('company_id', $companyId)->with('glAccount:id,account_code,account_name')->orderBy('code')->get();

        return Inertia::render('Accounting/TaxCodes/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'taxCodes' => $taxCodes->map(fn (TaxCode $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'rate' => (float) $t->rate,
                'tax_type' => $t->tax_type,
                'gl_account_label' => "{$t->glAccount->account_code} {$t->glAccount->account_name}",
                'is_active' => $t->is_active,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/TaxCodes/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            'accounts' => $this->accountOptions($companyId),
        ]);
    }

    public function store(StoreTaxCodeRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('accounting.tax-codes.index', ['company_id' => $request->input('company_id')])
            ->with('success', 'Tax code created.');
    }

    public function edit(TaxCode $taxCode): Response
    {
        return Inertia::render('Accounting/TaxCodes/Edit', [
            'taxCode' => $taxCode->only(['id', 'company_id', 'code', 'rate', 'tax_type', 'gl_account_id', 'is_active']),
            'accounts' => $this->accountOptions($taxCode->company_id),
        ]);
    }

    public function update(UpdateTaxCodeRequest $request, TaxCode $taxCode)
    {
        $this->service->update($taxCode, $request->validated());

        return redirect()->route('accounting.tax-codes.index', ['company_id' => $taxCode->company_id])
            ->with('success', 'Tax code updated.');
    }

    public function destroy(TaxCode $taxCode)
    {
        $companyId = $taxCode->company_id;
        $this->service->delete($taxCode);

        return redirect()->route('accounting.tax-codes.index', ['company_id' => $companyId])->with('success', 'Tax code deleted.');
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
