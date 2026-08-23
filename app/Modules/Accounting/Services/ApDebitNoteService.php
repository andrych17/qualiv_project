<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\ApDebitNote;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3E — mirrors ArCreditNoteService. Posts Dr AP control (reduce the liability) / Cr
 * Expense (reverse part of the original expense) — the AP-direction mirror of AR's
 * credit note entry. Does NOT touch the linked bill's input Faktur Pajak or Bukti
 * Potong — same deferred-correction-procedure treatment as the AR side.
 */
class ApDebitNoteService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ApBillService $bills,
    ) {}

    /** @param  array{company_id:int, partner_id:int, ap_bill_id?:?int, debit_date:string, amount:float, reason?:?string, expense_account_id:int}  $header */
    public function create(array $header, ?int $userId): ApDebitNote
    {
        return DB::transaction(function () use ($header, $userId) {
            $company = Company::query()->lockForUpdate()->findOrFail($header['company_id']);

            return ApDebitNote::query()->create([
                ...$header,
                'uuid' => (string) Str::uuid(),
                'debit_note_no' => $this->nextNumber($company),
                'status' => ApDebitNote::STATUS_DRAFT,
                'created_by' => $userId,
            ])->refresh();
        });
    }

    public function post(ApDebitNote $note, int $userId): ApDebitNote
    {
        if ($note->status !== ApDebitNote::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft debit note can be posted.']);
        }

        return DB::transaction(function () use ($note, $userId) {
            $note->load('bill', 'company');
            $company = $note->company ?? Company::query()->findOrFail($note->company_id);

            if (! $company->ap_control_account_id) {
                throw ValidationException::withMessages(['company_id' => "{$company->legal_name} has no AP control account configured — set one on the company before posting debit notes."]);
            }

            if ($note->bill !== null && (float) $note->amount > $note->bill->openBalance() + 0.005) {
                throw ValidationException::withMessages(['amount' => 'Debit note amount exceeds the linked bill\'s open balance.']);
            }

            $period = FiscalPeriod::query()
                ->where('company_id', $company->id)
                ->where('status', FiscalPeriod::STATUS_OPEN)
                ->where('start_date', '<=', $note->debit_date)
                ->where('end_date', '>=', $note->debit_date)
                ->first();
            if ($period === null) {
                throw ValidationException::withMessages(['debit_date' => 'No open fiscal period covers this debit note\'s date.']);
            }

            $journal = $this->journals->create([
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'journal_date' => $note->debit_date->toDateString(),
                'currency_code' => $company->base_currency,
                'memo' => "AP Debit Note {$note->debit_note_no}",
                'subject_type' => 'accounting.ap_debit_notes',
                'subject_id' => (string) $note->id,
            ], [
                ['account_id' => $company->ap_control_account_id, 'debit' => (float) $note->amount, 'description' => "Debit note {$note->debit_note_no}"],
                ['account_id' => $note->expense_account_id, 'credit' => (float) $note->amount, 'description' => "Debit note {$note->debit_note_no}"],
            ], $userId, 'ap');

            $this->journals->post($journal, $userId);

            $note->update(['status' => ApDebitNote::STATUS_POSTED, 'journal_id' => $journal->id]);

            if ($note->bill !== null) {
                $note->bill->increment('debited_amount', (float) $note->amount);
                $this->bills->recalculateStatus($note->bill->refresh());
            }

            return $note->refresh();
        });
    }

    private function nextNumber(Company $company): string
    {
        $year = now()->year;
        $seq = ApDebitNote::query()->where('company_id', $company->id)->whereYear('created_at', $year)->count() + 1;

        return sprintf('DN/%d/%05d', $year, $seq);
    }
}
