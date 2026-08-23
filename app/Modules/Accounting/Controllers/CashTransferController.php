<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Requests\StoreCashTransferRequest;
use App\Modules\Accounting\Services\CashTransferService;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** §3F — inter-account transfer. create()+post() run in one DB transaction, same review-step convention as CashTransactionController. No index/edit — each account's own cash book shows its transfers. */
class CashTransferController extends Controller
{
    public function __construct(private readonly CashTransferService $service, private readonly CompanyContextService $companyContext) {}

    public function create(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);
        $fromBankAccountId = (int) $request->integer('bank_account_id');

        return Inertia::render('Accounting/CashTransfers/Create', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'bankAccounts' => $this->bankAccountOptions($companyId),
            'selectedFromBankAccountId' => $fromBankAccountId ?: null,
        ]);
    }

    public function store(StoreCashTransferRequest $request)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request) {
            $transfer = $this->service->create($data, $request->user()->id);
            $this->service->post($transfer, $request->user()->id);
        });

        return redirect()->route('accounting.bank-accounts.show', $data['from_bank_account_id'])
            ->with('success', 'Transfer posted.');
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
}
