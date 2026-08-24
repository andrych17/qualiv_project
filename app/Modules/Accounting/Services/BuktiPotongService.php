<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\TaxBuktiPotong;
use App\Modules\Accounting\Models\TaxPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3M Bukti Potong issuance — always ours to number (unlike Faktur Pajak's input side,
 * there's no external document to transcribe from). Gap-free per (company_id, bp_type)
 * via MAX(sequence_no)+1, same mechanism as LEGAL.protocol_entries.sequence_number
 * (ProtocolBookService::recordEntry) — but there's no natural "book" row to lock here, so
 * the tenant's own `companies` row stands in as the lockable scope, coarse but correct.
 * A cancelled/replaced row still holds its number (MAX() sees it regardless of status),
 * matching "numbers are never reused."
 *
 * Not yet wired to a real caller: §3D/§3E (AR/AP) doesn't exist yet — issue() is written
 * for AP's bill-posting logic to call once it exists (§3E: "Bukti Potong record created
 * on posting").
 */
class BuktiPotongService
{
    public function __construct(protected TaxPeriodService $taxPeriods) {}

    public function issue(
        int $companyId,
        string $bpType,
        int $apBillId,
        int $withholdingTypeId,
        int $partnerId,
        float $grossAmount,
        float $withheldAmount,
        string $issueDate,
    ): TaxBuktiPotong {
        return DB::transaction(function () use ($companyId, $bpType, $apBillId, $withholdingTypeId, $partnerId, $grossAmount, $withheldAmount, $issueDate) {
            [$sequenceNo, $bpNumber] = $this->drawNextNumber($companyId, $bpType, $issueDate);

            return $this->createRow([
                'company_id' => $companyId,
                'bp_type' => $bpType,
                'ap_bill_id' => $apBillId,
                'withholding_type_id' => $withholdingTypeId,
                'partner_id' => $partnerId,
                'sequence_no' => $sequenceNo,
                'bp_number' => $bpNumber,
                'gross_amount' => $grossAmount,
                'withheld_amount' => $withheldAmount,
                'issued_at' => $issueDate,
            ]);
        });
    }

    /** §3M: corrections happen via a replacement record referencing the original — never edit-in-place. */
    public function replace(TaxBuktiPotong $original, float $grossAmount, float $withheldAmount): TaxBuktiPotong
    {
        $this->assertIssued($original);

        return DB::transaction(function () use ($original, $grossAmount, $withheldAmount) {
            [$sequenceNo, $bpNumber] = $this->drawNextNumber($original->company_id, $original->bp_type, now()->toDateString());

            $replacement = $this->createRow([
                'company_id' => $original->company_id,
                'bp_type' => $original->bp_type,
                'ap_bill_id' => $original->ap_bill_id,
                'withholding_type_id' => $original->withholding_type_id,
                'partner_id' => $original->partner_id,
                'sequence_no' => $sequenceNo,
                'bp_number' => $bpNumber,
                'gross_amount' => $grossAmount,
                'withheld_amount' => $withheldAmount,
                'issued_at' => now(),
                'replaces_bp_id' => $original->id,
            ]);

            $original->update(['status' => TaxBuktiPotong::STATUS_REPLACED]);

            return $replacement;
        });
    }

    public function cancel(TaxBuktiPotong $buktiPotong): void
    {
        $this->assertIssued($buktiPotong);

        DB::transaction(function () use ($buktiPotong) {
            $buktiPotong->update(['status' => TaxBuktiPotong::STATUS_CANCELLED]);

            AuditLog::record([
                'company_id' => $buktiPotong->company_id,
                'action' => AuditLog::ACTION_TAX_DOCUMENT_CANCELLED,
                'subject_type' => 'accounting.tax_bukti_potongs',
                'subject_id' => $buktiPotong->id,
            ]);
        });
    }

    private function assertIssued(TaxBuktiPotong $buktiPotong): void
    {
        if ($buktiPotong->status !== TaxBuktiPotong::STATUS_ISSUED) {
            throw ValidationException::withMessages(['status' => 'Only an issued Bukti Potong can be replaced or cancelled.']);
        }
    }

    /** @return array{0: int, 1: string} [sequence_no, formatted bp_number] */
    private function drawNextNumber(int $companyId, string $bpType, string $issueDate): array
    {
        Company::query()->lockForUpdate()->findOrFail($companyId);

        $next = (int) TaxBuktiPotong::query()
            ->where('company_id', $companyId)
            ->where('bp_type', $bpType)
            ->max('sequence_no') + 1;

        $year = Carbon::parse($issueDate)->format('Y');
        $bpNumber = "{$bpType}/{$year}/".str_pad((string) $next, 6, '0', STR_PAD_LEFT);

        return [$next, $bpNumber];
    }

    /**
     * The single row-creation point for issue()/replace() — one audit hook covers both
     * "a Bukti Potong now exists" callers, same convention as FakturPajakService::createRow().
     *
     * @param  array<string, mixed>  $data
     */
    private function createRow(array $data): TaxBuktiPotong
    {
        return DB::transaction(function () use ($data) {
            $masaPajak = Carbon::parse($data['issued_at'])->format('Y-m');
            $this->taxPeriods->ensurePeriod($data['company_id'], TaxPeriod::OBLIGATION_PPH, $masaPajak);

            $buktiPotong = TaxBuktiPotong::query()->create([
                'uuid' => (string) Str::uuid(),
                'status' => TaxBuktiPotong::STATUS_ISSUED,
                ...$data,
            ]);

            AuditLog::record([
                'company_id' => $buktiPotong->company_id,
                'action' => AuditLog::ACTION_TAX_DOCUMENT_ISSUED,
                'subject_type' => 'accounting.tax_bukti_potongs',
                'subject_id' => $buktiPotong->id,
            ]);

            return $buktiPotong;
        });
    }
}
