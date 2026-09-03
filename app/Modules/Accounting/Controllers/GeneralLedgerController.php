<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3N — General Ledger landing page: per-company account picker into
 * AccountLedgerController::show(), the same drill-down Trial Balance rows already use.
 * No date/period filter here — that's what the ledger detail page itself is for.
 */
class GeneralLedgerController extends Controller
{
    public function __construct(private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $accounts = Account::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'account_name', 'account_type', 'normal_balance']);

        return Inertia::render('Accounting/GeneralLedger/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'accounts' => $accounts,
        ]);
    }
}
