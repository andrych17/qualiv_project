<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Requests\StoreBankAccountRequest;
use App\Modules\Accounting\Requests\UpdateBankAccountRequest;
use App\Modules\Accounting\Services\BankAccountService;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3F cash/bank accounts. show() is the "cash book" — it does NOT read from
 * cash_transactions; it queries gl_journal_lines directly by this account's
 * gl_account_id, so it reflects everything that ever touched the account (AR/AP
 * payments, cash in/out, transfers, manual journals), not just entries made
 * through this module's own guided forms. See CashTransactionService docblock.
 */
class BankAccountController extends Controller
{
    public function __construct(private readonly BankAccountService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $bankAccounts = BankAccount::query()->where('company_id', $companyId)->with('glAccount:id,account_code,account_name')->orderBy('name')->get();

        return Inertia::render('Accounting/BankAccounts/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'bankAccounts' => $bankAccounts->map(fn (BankAccount $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'bank_name' => $b->bank_name,
                'account_number_masked' => BankAccountService::maskAccountNumber($b->account_number),
                'currency_code' => $b->currency_code,
                'gl_account_label' => "{$b->glAccount->account_code} {$b->glAccount->account_name}",
                'is_active' => $b->is_active,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/BankAccounts/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            'currencies' => Currency::query()->where('is_enabled', true)->orderBy('code')->get(['code', 'name']),
            'accounts' => $this->accountOptions($companyId),
        ]);
    }

    public function store(StoreBankAccountRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('accounting.bank-accounts.index', ['company_id' => $request->input('company_id')])
            ->with('success', 'Bank account created.');
    }

    public function edit(BankAccount $bankAccount): Response
    {
        return Inertia::render('Accounting/BankAccounts/Edit', [
            'bankAccount' => $bankAccount->only([
                'id', 'company_id', 'name', 'bank_name', 'account_number', 'account_holder_name',
                'currency_code', 'gl_account_id', 'is_active',
            ]),
            'currencies' => Currency::query()->where('is_enabled', true)->orderBy('code')->get(['code', 'name']),
            'accounts' => $this->accountOptions($bankAccount->company_id),
        ]);
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount)
    {
        $this->service->update($bankAccount, $request->validated());

        return redirect()->route('accounting.bank-accounts.index', ['company_id' => $bankAccount->company_id])
            ->with('success', 'Bank account updated.');
    }

    public function destroy(BankAccount $bankAccount)
    {
        $companyId = $bankAccount->company_id;
        $this->service->delete($bankAccount);

        return redirect()->route('accounting.bank-accounts.index', ['company_id' => $companyId])->with('success', 'Bank account deleted.');
    }

    /** The cash book — GL-derived, see class docblock. */
    public function show(BankAccount $bankAccount): Response
    {
        $bankAccount->load('glAccount', 'company');

        $book = $this->service->cashBook($bankAccount);

        return Inertia::render('Accounting/BankAccounts/Show', [
            'bankAccount' => [
                'id' => $bankAccount->id,
                'company_id' => $bankAccount->company_id,
                'name' => $bankAccount->name,
                'bank_name' => $bankAccount->bank_name,
                'account_number_masked' => BankAccountService::maskAccountNumber($bankAccount->account_number),
                'currency_code' => $bankAccount->currency_code,
                'is_base_currency' => $bankAccount->currency_code === $bankAccount->company->base_currency,
                'gl_account_label' => "{$bankAccount->glAccount->account_code} {$bankAccount->glAccount->account_name}",
            ],
            'lines' => $book['rows'],
            'closingBalance' => $book['closingBalance'],
        ]);
    }

    private function accountOptions(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return Account::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_control_account', false)
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'account_name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->account_code} {$a->account_name}"])
            ->values()
            ->all();
    }
}
