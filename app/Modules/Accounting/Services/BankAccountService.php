<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\BankAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** §3F — cash/bank account master, plain CRUD. */
class BankAccountService
{
    public function __construct(private readonly AccountLedgerService $accountLedgerService) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): BankAccount
    {
        return BankAccount::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(BankAccount $bankAccount, array $data): BankAccount
    {
        return DB::transaction(function () use ($bankAccount, $data) {
            $before = $bankAccount->toArray();
            $bankAccount->update($data);

            AuditLog::record([
                'company_id' => $bankAccount->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.bank_accounts',
                'subject_id' => $bankAccount->id,
                'before_snapshot' => $before,
                'after_snapshot' => $bankAccount->toArray(),
            ]);

            return $bankAccount->refresh();
        });
    }

    public function delete(BankAccount $bankAccount): void
    {
        DB::transaction(function () use ($bankAccount) {
            AuditLog::record([
                'company_id' => $bankAccount->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.bank_accounts',
                'subject_id' => $bankAccount->id,
                'before_snapshot' => $bankAccount->toArray(),
            ]);

            $bankAccount->delete();
        });
    }

    /**
     * The GL-derived cash book — see BankAccountController's class docblock for why this
     * doesn't read cash_transactions. §3Q's reconciliation worksheet reuses this exact
     * computation for "book balance" rather than re-deriving it, so the two screens can
     * never disagree about what the books say.
     *
     * @return array{rows: Collection, closingBalance: float}
     */
    public function cashBook(BankAccount $bankAccount): array
    {
        $bankAccount->loadMissing('glAccount');

        return $this->accountLedgerService->forAccount($bankAccount->glAccount);
    }

    public static function maskAccountNumber(?string $accountNumber): ?string
    {
        if ($accountNumber === null || $accountNumber === '') {
            return null;
        }

        $last4 = substr($accountNumber, -4);

        return str_repeat('•', max(strlen($accountNumber) - 4, 0)).$last4;
    }
}
