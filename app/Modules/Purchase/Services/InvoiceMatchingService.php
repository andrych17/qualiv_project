<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Purchase\Models\PurException;
use App\Modules\Purchase\Models\PurInvoiceHdr;
use App\Modules\Purchase\Models\PurInvoiceLine;
use App\Modules\Purchase\Models\PurInvoiceMatch;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurReceiptHdr;
use App\Modules\Purchase\Models\PurReceiptLine;
use App\Modules\WorkflowEngine\Exceptions\WorkflowEngineException;
use App\Modules\WorkflowEngine\Services\WorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceMatchingService
{
    /**
     * Captures a vendor invoice and automatically executes three-way matching (§3F).
     *
     * @param  array<string, mixed>  $data
     */
    public function captureInvoice(array $data, int $userId): PurInvoiceHdr
    {
        $po = PurOrderHdr::with(['lines', 'supplier'])->findOrFail($data['po_id']);

        // Prevent duplicate invoice number for the same supplier
        $exists = PurInvoiceHdr::query()
            ->where('supplier_id', $po->supplier_id)
            ->where('supplier_invoice_no', $data['supplier_invoice_no'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'supplier_invoice_no' => ["Invoice '{$data['supplier_invoice_no']}' has already been captured for this supplier."],
            ]);
        }

        return DB::transaction(function () use ($po, $data, $userId) {
            $totalAmount = 0.0;
            $lines = $data['lines'] ?? [];

            foreach ($lines as $line) {
                $qty = (float) ($line['qty'] ?? 0);
                $unitPrice = (float) ($line['unit_price'] ?? 0);
                $totalAmount += ($qty * $unitPrice);
            }

            $invoice = PurInvoiceHdr::create([
                'po_id' => $po->id,
                'supplier_id' => $po->supplier_id,
                'supplier_invoice_no' => $data['supplier_invoice_no'],
                'supplier_invoice_date' => $data['supplier_invoice_date'] ?? now()->toDateString(),
                'currency_code' => $po->currency_code ?? 'IDR',
                'amount' => $data['amount'] ?? $totalAmount,
                'dms_document_id' => $data['dms_document_id'] ?? null,
                'submission_channel' => $data['submission_channel'] ?? 'manual',
                'match_status' => PurInvoiceHdr::MATCH_PENDING,
                'status' => PurInvoiceHdr::STATUS_CAPTURED,
                'created_by' => $userId,
            ]);

            foreach ($lines as $l) {
                $qty = (float) ($l['qty'] ?? 0);
                $unitPrice = (float) ($l['unit_price'] ?? 0);

                $invoice->lines()->create([
                    'po_line_id' => (int) $l['po_line_id'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_amount' => $qty * $unitPrice,
                ]);
            }

            // Immediately run 3-way match
            $this->performThreeWayMatch($invoice);

            return $invoice->fresh(['lines.poLine', 'matches.poLine', 'order.supplier', 'creator']);
        });
    }

    /**
     * Performs authoritative Three-Way Match (§3F):
     * PO Line (ordered qty & price) vs GR Line (physical received qty) vs Invoice Line (billed qty & price).
     */
    public function performThreeWayMatch(
        PurInvoiceHdr $invoice,
        float $qtyTolerancePct = 0.0,
        float $priceTolerancePct = 0.0
    ): PurInvoiceHdr {
        return DB::transaction(function () use ($invoice, $qtyTolerancePct, $priceTolerancePct) {
            $invoice->load(['lines.poLine', 'order.lines']);

            // Get total GR quantity received for each PO line across all posted receipts
            $grTotals = PurReceiptLine::query()
                ->whereHas('receipt', function ($q) use ($invoice) {
                    $q->where('po_id', $invoice->po_id)
                        ->where('status', PurReceiptHdr::STATUS_POSTED);
                })
                ->groupBy('po_line_id')
                ->selectRaw('po_line_id, SUM(quantity_received) as total_received')
                ->pluck('total_received', 'po_line_id');

            // Clear previous match records for idempotency
            $invoice->matches()->delete();

            $allWithinTolerance = true;

            foreach ($invoice->lines as $invLine) {
                $poLine = $invLine->poLine;
                if (! $poLine) {
                    continue;
                }

                $poQty = (float) $poLine->qty_ordered;
                $poPrice = (float) $poLine->unit_price;
                $grQty = (float) ($grTotals->get($poLine->id) ?? 0.0);
                $invQty = (float) $invLine->qty;
                $invPrice = (float) $invLine->unit_price;

                // Variance against GR (physical intake) for quantity, and against PO for price
                $qtyVariancePct = $grQty > 0
                    ? (abs($invQty - $grQty) / $grQty) * 100.0
                    : ($invQty > 0 ? 100.0 : 0.0);

                $priceVariancePct = $poPrice > 0
                    ? (abs($invPrice - $poPrice) / $poPrice) * 100.0
                    : ($invPrice > 0 ? 100.0 : 0.0);

                $withinTolerance = ($qtyVariancePct <= $qtyTolerancePct) && ($priceVariancePct <= $priceTolerancePct);
                if (! $withinTolerance) {
                    $allWithinTolerance = false;
                }

                PurInvoiceMatch::create([
                    'invoice_id' => $invoice->id,
                    'po_line_id' => $poLine->id,
                    'po_qty' => $poQty,
                    'po_price' => $poPrice,
                    'gr_qty' => $grQty,
                    'invoice_qty' => $invQty,
                    'invoice_price' => $invPrice,
                    'qty_variance_pct' => round($qtyVariancePct, 2),
                    'price_variance_pct' => round($priceVariancePct, 2),
                    'within_tolerance' => $withinTolerance,
                ]);
            }

            $invoice->match_status = $allWithinTolerance
                ? PurInvoiceHdr::MATCH_MATCHED
                : PurInvoiceHdr::MATCH_MISMATCH;
            $invoice->save();

            // Log exception if mismatch occurs (§3K)
            if (! $allWithinTolerance) {
                PurException::firstOrCreate([
                    'exception_type' => PurException::TYPE_UNMATCHED_INVOICE,
                    'subject_type' => 'purchase.pur_invoice_hdrs',
                    'subject_id' => $invoice->id,
                ], [
                    'summary' => "Invoice {$invoice->supplier_invoice_no} failed 3-way match against PO {$invoice->order?->po_no}.",
                    'status' => PurException::STATUS_OPEN,
                ]);
            }

            return $invoice->fresh('matches');
        });
    }

    public function submitForApproval(PurInvoiceHdr $invoice, int $userId): PurInvoiceHdr
    {
        if ($invoice->status !== PurInvoiceHdr::STATUS_CAPTURED) {
            throw ValidationException::withMessages([
                'status' => ["Invoice in status '{$invoice->status}' cannot be submitted."],
            ]);
        }

        $invoice->status = PurInvoiceHdr::STATUS_PENDING_APPROVAL;
        $invoice->save();

        try {
            if (class_exists(WorkflowService::class) && app()->bound(WorkflowService::class)) {
                app(WorkflowService::class)->start(
                    'purchase.invoice_approval',
                    'purchase.pur_invoice_hdrs',
                    $invoice->id,
                    $userId
                );
            }
        } catch (WorkflowEngineException) {
            // Graceful fallback if workflow definition is unconfigured
        }

        return $invoice->refresh();
    }

    public function approve(PurInvoiceHdr $invoice, int $userId): PurInvoiceHdr
    {
        $invoice->load(['lines.poLine', 'order.supplier']);

        $invoice->status = PurInvoiceHdr::STATUS_APPROVED;

        // §3F / §5: On approval, handoff to Accounting AP engine (BillRequested) if Accounting is installed
        if (class_exists(AccountingService::class) && app()->bound(AccountingService::class)) {
            try {
                $accounting = app(AccountingService::class);
                $companyId = Company::query()->value('id') ?? 1;

                $header = [
                    'company_id' => $companyId,
                    'partner_id' => $invoice->supplier_id,
                    'bill_no' => $invoice->supplier_invoice_no,
                    'currency_code' => $invoice->currency_code ?? 'IDR',
                    'issue_date' => $invoice->supplier_invoice_date->toDateString(),
                    'due_date' => now()->addDays($invoice->order?->payment_terms_days ?? 30)->toDateString(),
                    'subject_type' => 'purchase.pur_invoice_hdrs',
                    'subject_id' => $invoice->id,
                ];

                $lines = $invoice->lines->map(fn (PurInvoiceLine $l) => [
                    'description' => $l->poLine?->description ?? "Item line #{$l->po_line_id}",
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price,
                    'expense_account_id' => 1, // default mapped expense / AP clearing account
                ])->all();

                $apBill = $accounting->createBill($header, $lines, $userId);
                $invoice->ap_bill_id = $apBill->id;
                $invoice->status = PurInvoiceHdr::STATUS_SENT_TO_ACCOUNTING;
            } catch (\Throwable) {
                // Keep invoice approved even if accounting handoff fails gracefully
            }
        }

        $invoice->save();

        return $invoice->refresh();
    }

    public function reject(PurInvoiceHdr $invoice, int $userId, ?string $reason = null): PurInvoiceHdr
    {
        $invoice->status = PurInvoiceHdr::STATUS_REJECTED;
        $invoice->save();

        return $invoice->refresh();
    }
}
