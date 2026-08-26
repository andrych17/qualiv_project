<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Purchase\Models\PurException;
use App\Modules\Purchase\Models\PurInvoiceHdr;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use App\Modules\WorkflowEngine\Exceptions\WorkflowEngineException;
use App\Modules\WorkflowEngine\Services\WorkflowService;
use Illuminate\Support\Facades\DB;

class ExceptionService
{
    /**
     * Logs an exception into the centralized append-style pur_exceptions table (§3K).
     */
    public function log(string $type, string $subjectType, int $subjectId, string $summary): PurException
    {
        $exception = PurException::firstOrCreate([
            'exception_type' => $type,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'status' => PurException::STATUS_OPEN,
        ], [
            'summary' => $summary,
        ]);

        // Trigger WNE Notification / Escalation if available
        try {
            if (class_exists(WorkflowService::class) && app()->bound(WorkflowService::class)) {
                app(WorkflowService::class)->start(
                    'purchase.exception_escalation',
                    $subjectType,
                    $subjectId,
                    null
                );
            }
        } catch (WorkflowEngineException) {
            // Gracefully ignore if workflow engine definition is not published
        }

        return $exception;
    }

    public function resolve(PurException $exception, int $userId): PurException
    {
        $exception->status = PurException::STATUS_RESOLVED;
        $exception->resolved_by = $userId;
        $exception->resolved_at = now();
        $exception->save();

        return $exception->refresh();
    }

    public function dismiss(PurException $exception, int $userId): PurException
    {
        $exception->status = PurException::STATUS_DISMISSED;
        $exception->resolved_by = $userId;
        $exception->resolved_at = now();
        $exception->save();

        return $exception->refresh();
    }

    /**
     * Scans for late deliveries: POs past expected delivery date with unreceived items.
     *
     * @return int Count of new exceptions logged
     */
    public function scanLateDeliveries(): int
    {
        $lateOrders = PurOrderHdr::query()
            ->whereIn('status', [
                PurOrderHdr::STATUS_SENT,
                PurOrderHdr::STATUS_ACKNOWLEDGED,
                PurOrderHdr::STATUS_PARTIALLY_RECEIVED,
            ])
            ->whereNotNull('expected_delivery_date')
            ->where('expected_delivery_date', '<', now()->toDateString())
            ->with(['supplier', 'lines'])
            ->get();

        $count = 0;
        foreach ($lateOrders as $po) {
            $hasPendingLines = $po->lines->contains(fn ($l) => (float) $l->qty_received < (float) $l->qty_ordered);
            if ($hasPendingLines) {
                $daysLate = now()->diffInDays($po->expected_delivery_date);
                $this->log(
                    PurException::TYPE_LATE_DELIVERY,
                    'purchase.pur_order_hdrs',
                    $po->id,
                    "PO {$po->po_no} from {$po->supplier?->name} is overdue by {$daysLate} day(s) (expected {$po->expected_delivery_date->toDateString()})."
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Scans for overdue approvals (> 3 days pending approval).
     *
     * @return int Count of new exceptions logged
     */
    public function scanOverdueApprovals(int $slaDays = 3): int
    {
        $threshold = now()->subDays($slaDays);
        $count = 0;

        // 1. Requisitions
        $overduePrs = PurRequisitionHdr::query()
            ->where('status', PurRequisitionHdr::STATUS_PENDING_APPROVAL)
            ->where('updated_at', '<', $threshold)
            ->get();

        foreach ($overduePrs as $pr) {
            $this->log(
                PurException::TYPE_OVERDUE_APPROVAL,
                'purchase.pur_requisition_hdrs',
                $pr->id,
                "Purchase Requisition {$pr->pr_no} has been pending approval for > {$slaDays} days."
            );
            $count++;
        }

        // 2. Orders
        $overduePos = PurOrderHdr::query()
            ->where('status', PurOrderHdr::STATUS_PENDING_APPROVAL)
            ->where('updated_at', '<', $threshold)
            ->get();

        foreach ($overduePos as $po) {
            $this->log(
                PurException::TYPE_OVERDUE_APPROVAL,
                'purchase.pur_order_hdrs',
                $po->id,
                "Purchase Order {$po->po_no} has been pending approval for > {$slaDays} days."
            );
            $count++;
        }

        // 3. Invoices
        $overdueInvoices = PurInvoiceHdr::query()
            ->where('status', PurInvoiceHdr::STATUS_PENDING_APPROVAL)
            ->where('updated_at', '<', $threshold)
            ->get();

        foreach ($overdueInvoices as $inv) {
            $this->log(
                PurException::TYPE_OVERDUE_APPROVAL,
                'purchase.pur_invoice_hdrs',
                $inv->id,
                "Vendor Invoice {$inv->supplier_invoice_no} has been pending approval for > {$slaDays} days."
            );
            $count++;
        }

        return $count;
    }
}
