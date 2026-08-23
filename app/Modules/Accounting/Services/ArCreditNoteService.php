<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\ArCreditNote;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3D — the only representation of a credit adjustment (ArInvoice::TYPE_*
 * deliberately has no `credit_memo` value). v1 posts a straight revenue reversal
 * against the AR control account; it does NOT touch a linked invoice's Faktur
 * Pajak (§3M) — a DJP-compliant "Nota Retur"/Faktur Pengganti correction is a
 * real-world follow-up procedure a tax preparer still has to do by hand today,
 * same class of deferred nuance as §3M's unverified Coretax XML shape.
 */
class ArCreditNoteService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ArInvoiceService $invoices,
    ) {}

    /** @param  array{company_id:int, partner_id:int, ar_invoice_id?:?int, credit_date:string, amount:float, reason?:?string, revenue_account_id:int}  $header */
    public function create(array $header, ?int $userId): ArCreditNote
    {
        return DB::transaction(function () use ($header, $userId) {
            $company = Company::query()->lockForUpdate()->findOrFail($header['company_id']);

            return ArCreditNote::query()->create([
                ...$header,
                'uuid' => (string) Str::uuid(),
                'credit_note_no' => $this->nextNumber($company),
                'status' => ArCreditNote::STATUS_DRAFT,
                'created_by' => $userId,
            ])->refresh();
        });
    }

    public function post(ArCreditNote $note, int $userId): ArCreditNote
    {
        if ($note->status !== ArCreditNote::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft credit note can be posted.']);
        }

        return DB::transaction(function () use ($note, $userId) {
            $note->load('invoice', 'company');
            $company = $note->company ?? Company::query()->findOrFail($note->company_id);

            if (! $company->ar_control_account_id) {
                throw ValidationException::withMessages(['company_id' => "{$company->legal_name} has no AR control account configured — set one on the company before posting credit notes."]);
            }

            if ($note->invoice !== null && (float) $note->amount > $note->invoice->openBalance() + 0.005) {
                throw ValidationException::withMessages(['amount' => 'Credit note amount exceeds the linked invoice\'s open balance.']);
            }

            $period = FiscalPeriod::query()
                ->where('company_id', $company->id)
                ->where('status', FiscalPeriod::STATUS_OPEN)
                ->where('start_date', '<=', $note->credit_date)
                ->where('end_date', '>=', $note->credit_date)
                ->first();
            if ($period === null) {
                throw ValidationException::withMessages(['credit_date' => 'No open fiscal period covers this credit note\'s date.']);
            }

            $journal = $this->journals->create([
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'journal_date' => $note->credit_date->toDateString(),
                'currency_code' => $company->base_currency,
                'memo' => "AR Credit Note {$note->credit_note_no}",
                'subject_type' => 'accounting.ar_credit_notes',
                'subject_id' => (string) $note->id,
            ], [
                ['account_id' => $note->revenue_account_id, 'debit' => (float) $note->amount, 'description' => "Credit note {$note->credit_note_no}"],
                ['account_id' => $company->ar_control_account_id, 'credit' => (float) $note->amount, 'description' => "AR — credit note {$note->credit_note_no}"],
            ], $userId, 'ar');

            $this->journals->post($journal, $userId);

            $note->update(['status' => ArCreditNote::STATUS_POSTED, 'journal_id' => $journal->id]);

            if ($note->invoice !== null) {
                $note->invoice->increment('credited_amount', (float) $note->amount);
                $this->invoices->recalculateStatus($note->invoice->refresh());
            }

            return $note->refresh();
        });
    }

    private function nextNumber(Company $company): string
    {
        $year = now()->year;
        $seq = ArCreditNote::query()->where('company_id', $company->id)->whereYear('created_at', $year)->count() + 1;

        return sprintf('CN/%d/%05d', $year, $seq);
    }
}
