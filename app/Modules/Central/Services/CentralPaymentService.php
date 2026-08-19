<?php

namespace App\Modules\Central\Services;

use App\Mail\PaymentRejectedMail;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Manual, bank-transfer-first payment flow (CENTRAL_SPECS.md §3F): tenant submits a receipt,
 * Simon confirms or rejects it. Receipts are never deleted, even on rejection — retained as
 * financial evidence. Central implements its own trivial flat storage rather than routing
 * through DMS (see spec §5's disambiguation).
 */
class CentralPaymentService
{
    public function __construct(
        protected CentralAuditLogger $auditLogger,
        protected CentralAccessStatusCache $accessStatusCache,
    ) {}

    /** Reachable from both the admin dashboard and the tenant-facing Billing screen (§3H). */
    public function submit(CentralInvoice $invoice, array $data): CentralPayment
    {
        return DB::transaction(function () use ($invoice, $data) {
            $payment = CentralPayment::query()->create([
                'invoice_id' => $invoice->id,
                'tenant_id' => $invoice->tenant_id,
                'amount' => $data['amount'],
                'method' => $data['method'] ?? 'bank_transfer',
                'paid_at' => $data['paid_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'status' => 'pending_review',
                'submitted_at' => now(),
            ]);

            if (isset($data['receipt']) && $data['receipt'] instanceof UploadedFile) {
                $payment->update(['receipt_object_key' => $this->storeReceipt($invoice->tenant_id, $payment->id, $data['receipt'])]);
            }

            $invoice->update(['status' => 'payment_submitted']);

            $this->auditLogger->log(
                action: 'payment_submitted',
                entityType: 'payment',
                entityId: (string) $payment->id,
                after: $payment->refresh()->toArray(),
            );

            return $payment;
        });
    }

    public function confirm(CentralPayment $payment, string $reviewerId): CentralPayment
    {
        return DB::transaction(function () use ($payment, $reviewerId) {
            // Serializes against ApplyDunningCutoff, which locks the same invoice row
            // (CENTRAL_SPECS.md §5): whichever lands second sees the other's outcome, so a
            // confirmed payment can never leave the tenant stuck read_only.
            $invoice = CentralInvoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);

            if ($payment->status !== 'pending_review') {
                abort(422, 'Only a pending payment can be confirmed.');
            }

            $before = $payment->toArray();

            $payment->update([
                'status' => 'confirmed',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            $invoice->update(['status' => 'paid']);

            // Reactivation is automatic the moment a payment is confirmed (§3G).
            $tenant = $invoice->tenant;
            if ($tenant && $tenant->access_status !== 'active') {
                $tenant->update(['access_status' => 'active']);
                $this->accessStatusCache->invalidate($tenant->getKey());
            }

            $this->auditLogger->log(
                action: 'payment_confirmed',
                entityType: 'payment',
                entityId: (string) $payment->id,
                before: $before,
                after: $payment->refresh()->toArray(),
                actorId: $reviewerId,
            );

            return $payment;
        });
    }

    public function reject(CentralPayment $payment, string $reviewerId, string $reason): CentralPayment
    {
        $payment = DB::transaction(function () use ($payment, $reviewerId, $reason) {
            if ($payment->status !== 'pending_review') {
                abort(422, 'Only a pending payment can be rejected.');
            }

            $before = $payment->toArray();

            $payment->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            // Reverts to issued, or overdue if the due date has passed — the derived state
            // the dunning sweep would have set anyway (CENTRAL_SPECS.md §3E/§3F).
            $invoice = $payment->invoice;
            $invoice->update([
                'status' => $invoice->due_date->isBefore(today()) ? 'overdue' : 'issued',
            ]);

            // Receipt file is deliberately NOT deleted (§3F) — kept as evidence.
            $this->auditLogger->log(
                action: 'payment_rejected',
                entityType: 'payment',
                entityId: (string) $payment->id,
                before: $before,
                after: $payment->refresh()->toArray(),
                actorId: $reviewerId,
            );

            return $payment;
        });

        // The tenant is notified and can resubmit (§3F) — sent after commit so a mail failure
        // can never roll back the review itself.
        $tenant = $payment->invoice->tenant;
        if ($tenant?->contact_email) {
            Mail::to($tenant->contact_email)->send(new PaymentRejectedMail($tenant, $payment->invoice, $reason));
        }

        return $payment;
    }

    private function storeReceipt(string $tenantId, int $paymentId, UploadedFile $file): string
    {
        $path = "central/tenants/{$tenantId}/receipts/{$paymentId}";

        return $file->store($path, 's3');
    }
}
