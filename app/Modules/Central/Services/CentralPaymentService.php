<?php

namespace App\Modules\Central\Services;

use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPayment;
use Illuminate\Support\Facades\DB;

class CentralPaymentService
{
    /**
     * Simplified from the spec's receipt-upload + review flow (CENTRAL_SPECS.md §3F) to a
     * single admin action: record the payment, flip the invoice straight to paid. No
     * pending_review state, no receipt upload — Simon is the only person marking these.
     */
    public function recordAndMarkPaid(CentralInvoice $invoice, array $data): CentralPayment
    {
        return DB::transaction(function () use ($invoice, $data) {
            $payment = CentralPayment::query()->create([
                'invoice_id' => $invoice->id,
                'tenant_id' => $invoice->tenant_id,
                'amount' => $data['amount'],
                'method' => $data['method'] ?? 'bank_transfer',
                'paid_at' => $data['paid_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $invoice->update(['status' => 'paid']);

            // Reactivation is automatic the moment a payment lands (CENTRAL_SPECS.md §3G) —
            // harmless no-op today since nothing sets read_only yet (Dunning isn't built).
            $invoice->tenant?->update(['access_status' => 'active']);

            return $payment;
        });
    }
}
