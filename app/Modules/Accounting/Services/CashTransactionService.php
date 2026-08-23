<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\CashTransaction;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3F — a cash in/out entry not tied to an AR/AP document. create()+post() run
 * together from the guided form, same split-but-called-together pattern as
 * ArPaymentController (the human submitting the form is the review step).
 *
 * Posts through JournalService with source='manual' — deliberately, not a new
 * 'cash' source — so the existing control-account guard (assertNoControlAccountLines)
 * applies for free. The bank side is always safe (cash/bank GL accounts are never
 * control accounts), but the user-picked offset account could be one (11000/21000/
 * 13000 in the starter COA); bypassing the guard here would let a cash-in screen
 * credit the AR control account directly, exactly the hole §3D/§3E exist to close.
 *
 * §3L: single rate resolved at post() time (bank account's own currency, transaction
 * date) — both journal lines share that one converted base amount, so it balances
 * trivially, same pattern as ArPaymentService.
 */
class CashTransactionService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    /** @param  array{company_id:int, bank_account_id:int, direction:string, transaction_date:string, amount:float, offset_account_id:int, description?:?string}  $header */
    public function create(array $header, ?int $userId): CashTransaction
    {
        return CashTransaction::query()->create([
            ...$header,
            'uuid' => (string) Str::uuid(),
            'status' => CashTransaction::STATUS_DRAFT,
            'created_by' => $userId,
        ])->refresh();
    }

    public function post(CashTransaction $transaction, int $userId): CashTransaction
    {
        if ($transaction->status !== CashTransaction::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft cash transaction can be posted.']);
        }

        return DB::transaction(function () use ($transaction, $userId) {
            $transaction->load('bankAccount', 'company');
            $company = $transaction->company ?? Company::query()->findOrFail($transaction->company_id);
            $bankAccount = $transaction->bankAccount;

            $period = FiscalPeriod::query()
                ->where('company_id', $company->id)
                ->where('status', FiscalPeriod::STATUS_OPEN)
                ->where('start_date', '<=', $transaction->transaction_date)
                ->where('end_date', '>=', $transaction->transaction_date)
                ->first();
            if ($period === null) {
                throw ValidationException::withMessages(['transaction_date' => 'No open fiscal period covers this transaction\'s date.']);
            }

            $rate = $this->exchangeRates->rateFor($company, $bankAccount->currency_code, $transaction->transaction_date->toDateString());
            $isForeign = $bankAccount->currency_code !== $company->base_currency;
            $base = round((float) $transaction->amount * $rate, 2);
            $fxTrio = $isForeign
                ? ['fx_currency_code' => $bankAccount->currency_code, 'fx_amount' => (float) $transaction->amount, 'fx_rate' => $rate]
                : [];

            $isIn = $transaction->direction === CashTransaction::DIRECTION_IN;
            $lines = [
                ['account_id' => $bankAccount->gl_account_id, 'debit' => $isIn ? $base : 0, 'credit' => $isIn ? 0 : $base, 'description' => $transaction->description, ...$fxTrio],
                ['account_id' => $transaction->offset_account_id, 'debit' => $isIn ? 0 : $base, 'credit' => $isIn ? $base : 0, 'description' => $transaction->description],
            ];

            $journal = $this->journals->create([
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'journal_date' => $transaction->transaction_date->toDateString(),
                'currency_code' => $bankAccount->currency_code,
                'memo' => $transaction->description ?? ($isIn ? 'Cash in' : 'Cash out'),
                'subject_type' => 'accounting.cash_transactions',
                'subject_id' => (string) $transaction->id,
            ], $lines, $userId, 'manual');

            $this->journals->post($journal, $userId);

            $transaction->update(['status' => CashTransaction::STATUS_POSTED, 'journal_id' => $journal->id]);

            return $transaction->refresh();
        });
    }
}
