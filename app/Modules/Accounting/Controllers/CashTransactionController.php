<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Requests\StoreCashTransactionRequest;
use App\Modules\Accounting\Services\CashTransactionService;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** §3F — cash in/out. create()+post() run in one DB transaction: the human submitting this form is the review step (see CashTransactionService docblock). No index/edit — the bank account's own Show page (the cash book) is the list view. */
class CashTransactionController extends Controller
{
    public function __construct(private readonly CashTransactionService $service, private readonly CompanyContextService $companyContext) {}

    public function create(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);
        $bankAccountId = (int) $request->integer('bank_account_id');

        return Inertia::render('Accounting/CashTransactions/Create', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'bankAccounts' => $this->bankAccountOptions($companyId),
            'selectedBankAccountId' => $bankAccountId ?: null,
            'accounts' => $this->accountOptions($companyId),
        ]);
    }

    public function store(StoreCashTransactionRequest $request)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request) {
            $transaction = $this->service->create($data, $request->user()->id);
            $this->service->post($transaction, $request->user()->id);
        });

        return redirect()->route('accounting.bank-accounts.show', $data['bank_account_id'])
            ->with('success', 'Cash transaction posted.');
    }

    private function bankAccountOptions(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return BankAccount::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'currency_code'])
            ->map(fn (BankAccount $b) => ['value' => $b->id, 'label' => "{$b->name} ({$b->currency_code})"])
            ->values()
            ->all();
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
