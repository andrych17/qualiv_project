<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\FakturPajakNumberBlock;
use App\Modules\Accounting\Models\TaxFakturPajak;
use App\Modules\Accounting\Models\TaxPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3M Faktur Pajak issuance. 'output' (Keluaran) numbers are ours to assign — drawn
 * sequentially from a tenant-entered DJP allocation block, row-locked the same way
 * LEGAL.protocol_books guards its sequence_number (ProtocolBookService::recordEntry).
 * A DJP-allocated number can never be reused, so a block running out is a loud failure,
 * not a silent wrap to the start (unlike SYSCONFIG.config_snums' wrap_low/wrap_high,
 * which is the wrong mechanism here for exactly that reason).
 *
 * 'input' (Masukan) numbers are the *vendor's* own Faktur Pajak number, transcribed when
 * recording an AP bill's input PPN — nothing to allocate, just validate and store.
 *
 * Not yet wired to a real caller: §3D/§3E (AR/AP) doesn't exist yet (see
 * ACCOUNTING_SPECS.md §5 build order — this engine ships first). issueOutput()/
 * recordInput() are written for AR/AP's posting logic to call once it exists.
 */
class FakturPajakService
{
    public function __construct(protected TaxPeriodService $taxPeriods) {}

    public function issueOutput(
        int $companyId,
        int $arInvoiceId,
        int $partnerId,
        ?string $buyerNpwpNik,
        float $taxBase,
        float $ppnAmount,
        string $issueDate,
    ): TaxFakturPajak {
        return DB::transaction(function () use ($companyId, $arInvoiceId, $partnerId, $buyerNpwpNik, $taxBase, $ppnAmount, $issueDate) {
            $nomor = $this->drawNextNumber($companyId);

            return $this->createRow([
                'company_id' => $companyId,
                'direction' => TaxFakturPajak::DIRECTION_OUTPUT,
                'nomor_seri_faktur' => $nomor,
                'ar_invoice_id' => $arInvoiceId,
                'partner_id' => $partnerId,
                'buyer_npwp_nik' => $buyerNpwpNik,
                'tax_base' => $taxBase,
                'ppn_amount' => $ppnAmount,
                'issued_at' => $issueDate,
            ]);
        });
    }

    public function recordInput(
        int $companyId,
        int $apBillId,
        int $partnerId,
        string $nomorSeriFaktur,
        float $taxBase,
        float $ppnAmount,
        string $issueDate,
    ): TaxFakturPajak {
        if (TaxFakturPajak::query()->where('company_id', $companyId)->where('nomor_seri_faktur', $nomorSeriFaktur)->exists()) {
            throw ValidationException::withMessages(['nomor_seri_faktur' => 'This Faktur Pajak number has already been recorded for this company.']);
        }

        return $this->createRow([
            'company_id' => $companyId,
            'direction' => TaxFakturPajak::DIRECTION_INPUT,
            'nomor_seri_faktur' => $nomorSeriFaktur,
            'ap_bill_id' => $apBillId,
            'partner_id' => $partnerId,
            'tax_base' => $taxBase,
            'ppn_amount' => $ppnAmount,
            'issued_at' => $issueDate,
        ]);
    }

    /** §3M: corrections happen via a replacement record referencing the original — never edit-in-place. */
    public function replace(TaxFakturPajak $original, float $taxBase, float $ppnAmount, ?string $newNomorSeriFakturForInput = null): TaxFakturPajak
    {
        $this->assertIssued($original);

        return DB::transaction(function () use ($original, $taxBase, $ppnAmount, $newNomorSeriFakturForInput) {
            if ($original->direction === TaxFakturPajak::DIRECTION_OUTPUT) {
                $nomor = $this->drawNextNumber($original->company_id);
            } else {
                if ($newNomorSeriFakturForInput === null) {
                    throw ValidationException::withMessages(['nomor_seri_faktur' => 'A replacement input Faktur Pajak needs the vendor\'s new number.']);
                }
                $nomor = $newNomorSeriFakturForInput;
            }

            $replacement = $this->createRow([
                'company_id' => $original->company_id,
                'direction' => $original->direction,
                'nomor_seri_faktur' => $nomor,
                'ar_invoice_id' => $original->ar_invoice_id,
                'ap_bill_id' => $original->ap_bill_id,
                'partner_id' => $original->partner_id,
                'buyer_npwp_nik' => $original->buyer_npwp_nik,
                'tax_base' => $taxBase,
                'ppn_amount' => $ppnAmount,
                'issued_at' => now(),
                'replaces_faktur_id' => $original->id,
            ]);

            $original->update(['status' => TaxFakturPajak::STATUS_REPLACED]);

            return $replacement;
        });
    }

    public function cancel(TaxFakturPajak $faktur): void
    {
        $this->assertIssued($faktur);

        DB::transaction(function () use ($faktur) {
            $faktur->update(['status' => TaxFakturPajak::STATUS_CANCELLED]);

            AuditLog::record([
                'company_id' => $faktur->company_id,
                'action' => AuditLog::ACTION_TAX_DOCUMENT_CANCELLED,
                'subject_type' => 'accounting.tax_faktur_pajaks',
                'subject_id' => $faktur->id,
            ]);
        });
    }

    private function assertIssued(TaxFakturPajak $faktur): void
    {
        if ($faktur->status !== TaxFakturPajak::STATUS_ISSUED) {
            throw ValidationException::withMessages(['status' => 'Only an issued Faktur Pajak can be replaced or cancelled.']);
        }
    }

    private function drawNextNumber(int $companyId): string
    {
        $block = FakturPajakNumberBlock::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($block === null) {
            throw ValidationException::withMessages(['nomor_seri_faktur' => 'No active Faktur Pajak number block for this company — allocate one first.']);
        }

        $next = ($block->last_issued ?? $block->range_start - 1) + 1;

        if ($next > $block->range_end) {
            throw ValidationException::withMessages(['nomor_seri_faktur' => "Faktur Pajak block for company #{$companyId} is exhausted — allocate a new DJP number block."]);
        }

        $block->update(['last_issued' => $next]);

        return $block->prefix.str_pad((string) $next, 8, '0', STR_PAD_LEFT);
    }

    /**
     * The single row-creation point for issueOutput()/recordInput()/replace() — one audit
     * hook here covers all three "a Faktur Pajak now exists" callers instead of three
     * separate ones, and always runs inside whichever DB::transaction() the caller already
     * opened, so the tax document and its audit row commit or roll back together.
     *
     * @param  array<string, mixed>  $data
     */
    private function createRow(array $data): TaxFakturPajak
    {
        return DB::transaction(function () use ($data) {
            $issueDate = $data['issued_at'] ?? now();
            $masaPajak = Carbon::parse($issueDate)->format('Y-m');
            $this->taxPeriods->ensurePeriod($data['company_id'], TaxPeriod::OBLIGATION_PPN, $masaPajak);

            $faktur = TaxFakturPajak::query()->create([
                'uuid' => (string) Str::uuid(),
                'status' => TaxFakturPajak::STATUS_ISSUED,
                ...$data,
            ]);

            AuditLog::record([
                'company_id' => $faktur->company_id,
                'action' => AuditLog::ACTION_TAX_DOCUMENT_ISSUED,
                'subject_type' => 'accounting.tax_faktur_pajaks',
                'subject_id' => $faktur->id,
            ]);

            return $faktur;
        });
    }
}
