<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\CashTransfer;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3F — moves funds between two of the company's own cash/bank accounts.
 *
 * §3L: same-currency only in v1, rejected outright otherwise. A cross-currency
 * transfer has two legs at potentially two different effective rates — what the
 * bank actually credited on the destination side won't exactly equal amount ×
 * our resolved rate, and that gap is realized FX gain/loss, the exact machinery
 * ArPaymentService/ApPaymentService already defer. Recording a computed number
 * that won't match the real bank credit would also work against this module's
 * whole point (reconciling to statements, §3Q). Same conservative call as the
 * credit/debit-note currency guards.
 *
 * Posts through JournalService with source='manual' for the same reason as
 * CashTransactionService — see its docblock.
 */
class CashTransferService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    /** @param  array{company_id:int, from_bank_account_id:int, to_bank_account_id:int, transfer_date:string, amount:float, description?:?string}  $header */
    public function create(array $header, ?int $userId): CashTransfer
    {
        if ($header['from_bank_account_id'] === $header['to_bank_account_id']) {
            throw ValidationException::withMessages(['to_bank_account_id' => 'Source and destination accounts must be different.']);
        }

        $from = BankAccount::query()->findOrFail($header['from_bank_account_id']);
        $to = BankAccount::query()->findOrFail($header['to_bank_account_id']);
        if ($from->currency_code !== $to->currency_code) {
            throw ValidationException::withMessages([
                'to_bank_account_id' => "Cross-currency transfers aren't supported yet ({$from->currency_code} → {$to->currency_code}) — the destination amount would depend on the bank's own settlement rate, not a rate this module can predict.",
            ]);
        }

        return CashTransfer::query()->create([
            ...$header,
            'uuid' => (string) Str::uuid(),
            'status' => CashTransfer::STATUS_DRAFT,
            'created_by' => $userId,
        ])->refresh();
    }

    public function post(CashTransfer $transfer, int $userId): CashTransfer
    {
        if ($transfer->status !== CashTransfer::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft transfer can be posted.']);
        }

        return DB::transaction(function () use ($transfer, $userId) {
            $transfer->load('fromBankAccount', 'toBankAccount', 'company');
            $company = $transfer->company ?? Company::query()->findOrFail($transfer->company_id);
            $from = $transfer->fromBankAccount;
            $to = $transfer->toBankAccount;

            $period = FiscalPeriod::query()
                ->where('company_id', $company->id)
                ->where('status', FiscalPeriod::STATUS_OPEN)
                ->where('start_date', '<=', $transfer->transfer_date)
                ->where('end_date', '>=', $transfer->transfer_date)
                ->first();
            if ($period === null) {
                throw ValidationException::withMessages(['transfer_date' => 'No open fiscal period covers this transfer\'s date.']);
            }

            // Both legs are the same currency (enforced at create()) — one rate,
            // one converted base amount on both sides, balances trivially.
            $rate = $this->exchangeRates->rateFor($company, $from->currency_code, $transfer->transfer_date->toDateString());
            $isForeign = $from->currency_code !== $company->base_currency;
            $base = round((float) $transfer->amount * $rate, 2);
            $fxTrio = $isForeign
                ? ['fx_currency_code' => $from->currency_code, 'fx_amount' => (float) $transfer->amount, 'fx_rate' => $rate]
                : [];

            $journal = $this->journals->create([
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'journal_date' => $transfer->transfer_date->toDateString(),
                'currency_code' => $from->currency_code,
                'memo' => $transfer->description ?? "Transfer {$from->name} → {$to->name}",
                'subject_type' => 'accounting.cash_transfers',
                'subject_id' => (string) $transfer->id,
            ], [
                ['account_id' => $to->gl_account_id, 'debit' => $base, 'description' => "Transfer in from {$from->name}", ...$fxTrio],
                ['account_id' => $from->gl_account_id, 'credit' => $base, 'description' => "Transfer out to {$to->name}", ...$fxTrio],
            ], $userId, 'manual');

            $this->journals->post($journal, $userId);

            $transfer->update(['status' => CashTransfer::STATUS_POSTED, 'journal_id' => $journal->id]);

            return $transfer->refresh();
        });
    }
}
