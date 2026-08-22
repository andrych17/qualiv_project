<?php

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedTax;
use App\Modules\SysConfig\Services\ConfigService;
use RuntimeException;

/**
 * §3K — computes and tracks the client's PPh Final / BPHTB obligations on a PPAT land
 * transfer. Never produces an Accounting journal entry (§3K "Rules/logic" — this is not
 * the firm's own tax, it's the client's, evidenced only).
 */
class TaxService
{
    public function __construct(
        protected ConfigService $config,
    ) {}

    /**
     * Seller (pihak_pertama) owes PPh Final; buyer (pihak_kedua) owes BPHTB — the closest
     * fit in §3J's generic role vocabulary to "seller"/"buyer" for a land transfer.
     */
    public function generateForDeed(Deed $deed): void
    {
        if (DeedTax::query()->where('deed_id', $deed->id)->exists()) {
            throw new RuntimeException('Tax records already exist for this deed.');
        }

        $seller = $deed->parties()->whereHas('roleType', fn ($q) => $q->where('code', 'pihak_pertama'))->first();
        $buyer = $deed->parties()->whereHas('roleType', fn ($q) => $q->where('code', 'pihak_kedua'))->first();

        $this->createTax($deed, DeedTax::TYPE_PPH_FINAL, $seller?->partner_id);
        $this->createTax($deed, DeedTax::TYPE_BPHTB, $buyer?->partner_id);
    }

    private function createTax(Deed $deed, string $taxType, ?int $taxpayerPartnerId): DeedTax
    {
        $rate = $taxType === DeedTax::TYPE_PPH_FINAL
            ? (float) ($this->config->get('LEGAL', 'PPH_FINAL_RATE', 'LEGAL') ?? 2.5)
            : (float) ($this->config->get('LEGAL', 'BPHTB_RATE', 'LEGAL') ?? 5.0);

        $npoptkp = $taxType === DeedTax::TYPE_BPHTB
            ? (float) ($this->config->get('LEGAL', 'BPHTB_NPOPTKP', 'LEGAL') ?? 0)
            : null;

        $tax = DeedTax::query()->create([
            'deed_id' => $deed->id,
            'tax_type' => $taxType,
            'taxpayer_partner_id' => $taxpayerPartnerId,
            'base_amount' => $deed->transaction_value ?? 0,
            'rate' => $rate,
            'npoptkp_applied' => $npoptkp,
            'computed_amount' => 0,
            'status' => DeedTax::STATUS_PENDING,
        ]);

        return $this->recompute($tax);
    }

    /** @param  array<string, mixed>  $data */
    public function updateAmounts(DeedTax $tax, array $data): DeedTax
    {
        $tax->update([
            'base_amount' => $data['base_amount'] ?? $tax->base_amount,
            'njop_amount' => $data['njop_amount'] ?? $tax->njop_amount,
            'rate' => $data['rate'] ?? $tax->rate,
            'npoptkp_applied' => $data['npoptkp_applied'] ?? $tax->npoptkp_applied,
        ]);

        return $this->recompute($tax);
    }

    /** Higher of transaction value or NJOP, minus NPOPTKP for BPHTB (§3K field list). */
    private function recompute(DeedTax $tax): DeedTax
    {
        $base = max((float) $tax->base_amount, (float) ($tax->njop_amount ?? 0));
        $taxable = max($base - (float) ($tax->npoptkp_applied ?? 0), 0);
        $tax->update(['computed_amount' => round($taxable * ((float) $tax->rate / 100), 2)]);

        return $tax->refresh();
    }

    public function issueBillingCode(DeedTax $tax, string $billingCode): DeedTax
    {
        if ($tax->status !== DeedTax::STATUS_PENDING) {
            throw new RuntimeException('A billing code can only be issued from pending.');
        }

        $tax->update(['billing_code' => $billingCode, 'status' => DeedTax::STATUS_BILLING_CODE_ISSUED]);

        return $tax->refresh();
    }

    public function markPaid(DeedTax $tax, string $ntpn): DeedTax
    {
        if ($tax->status !== DeedTax::STATUS_BILLING_CODE_ISSUED) {
            throw new RuntimeException('Only a billing-code-issued tax record can be marked paid.');
        }

        $tax->update(['ntpn' => $ntpn, 'status' => DeedTax::STATUS_PAID]);

        return $tax->refresh();
    }

    /**
     * Distinct from `paid` deliberately (§3K) — real practice has the PPAT (or the
     * DJP/Bapenda host-to-host check) confirm payment is *recognized*, not just made.
     */
    public function markValidated(DeedTax $tax): DeedTax
    {
        if ($tax->status !== DeedTax::STATUS_PAID) {
            throw new RuntimeException('Only a paid tax record can be validated.');
        }

        $tax->update(['status' => DeedTax::STATUS_VALIDATED]);

        return $tax->refresh();
    }
}
