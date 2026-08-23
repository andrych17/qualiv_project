<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Requests\StoreCompanyRequest;
use App\Modules\Accounting\Requests\UpdateCompanyRequest;
use App\Modules\Accounting\Services\CompanyService;
use Inertia\Inertia;
use Inertia\Response;

/** §3K minimal master (§3B's own dependency) — full switcher/combined reporting is a later build. */
class CompanyController extends Controller
{
    public function __construct(private readonly CompanyService $service) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Companies/Index', [
            'companies' => Company::query()->orderBy('legal_name')->get([
                'id', 'legal_name', 'npwp', 'base_currency', 'fiscal_year_start_month', 'is_active',
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/Companies/Create');
    }

    public function store(StoreCompanyRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('accounting.companies.index')->with('success', 'Company created.');
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('Accounting/Companies/Edit', [
            'company' => $company->only([
                'id', 'legal_name', 'npwp', 'address', 'base_currency', 'fiscal_year_start_month',
                'ar_control_account_id', 'ap_control_account_id', 'is_active',
            ]),
            'controlAccounts' => Account::query()
                ->where('company_id', $company->id)
                ->where('is_control_account', true)
                ->orderBy('account_code')
                ->get(['id', 'account_code', 'account_name'])
                ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} — {$a->account_name}"]),
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company)
    {
        $this->service->update($company, $request->validated());

        return redirect()->route('accounting.companies.index')->with('success', 'Company updated.');
    }
}
