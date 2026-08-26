<?php

namespace App\Modules\Sales\Services;

use App\Modules\Accounting\Services\ArCreditNoteService;
use App\Modules\Sales\Models\CommissionPlan;
use App\Modules\Sales\Models\CommissionSettlement;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnService
{
    public function __construct(
        protected SalesOrderService $salesOrderService,
    ) {}

    /**
     * Create a return request against an order or invoice.
     */
    public function create(array $data, ?int $userId): SalesReturn
    {
        return DB::transaction(function () use ($data, $userId) {
            $return = SalesReturn::create([
                'so_hdr_id' => $data['so_hdr_id'] ?? null,
                'accounting_invoice_id' => $data['accounting_invoice_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'reason_code' => $data['reason_code'],
                'status' => SalesReturn::STATUS_REQUESTED,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'created_by' => $userId,
            ]);

            $lines = $data['lines'] ?? [];
            foreach ($lines as $lineData) {
                $return->lines()->create([
                    'so_line_id' => $lineData['so_line_id'] ?? null,
                    'qty_returned' => $lineData['qty_returned'],
                    'condition_notes' => $lineData['condition_notes'] ?? null,
                ]);
            }

            return $return->load(['lines.salesOrderLine', 'order', 'customer']);
        });
    }

    /**
     * Approve return request.
     */
    public function approve(SalesReturn $return): SalesReturn
    {
        if ($return->status !== SalesReturn::STATUS_REQUESTED) {
            throw ValidationException::withMessages([
                'status' => ['Only requested returns can be approved.'],
            ]);
        }

        $return->update(['status' => SalesReturn::STATUS_APPROVED]);

        return $return;
    }

    /**
     * Mark return items as received in warehouse.
     */
    public function markReceived(SalesReturn $return): SalesReturn
    {
        if (! in_array($return->status, [SalesReturn::STATUS_REQUESTED, SalesReturn::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Return must be requested or approved to be received.'],
            ]);
        }

        $return->update(['status' => SalesReturn::STATUS_RECEIVED]);

        return $return;
    }

    /**
     * Process refund path (request credit note / reversal).
     */
    public function processRefund(SalesReturn $return, ?int $userId = null): SalesReturn
    {
        if ($return->status !== SalesReturn::STATUS_RECEIVED) {
            throw ValidationException::withMessages([
                'status' => ['Return items must be received before processing a refund.'],
            ]);
        }

        return DB::transaction(function () use ($return, $userId) {
            $return->load(['lines.salesOrderLine.order', 'order']);

            // If Accounting ArCreditNoteService is available, issue credit note
            if ($return->accounting_invoice_id && class_exists(ArCreditNoteService::class)) {
                // Issue credit note in Accounting
            }

            // Commission reversal line on the next open/draft settlement (§3M/§3J)
            $this->recordCommissionReversals($return);

            $return->update(['status' => SalesReturn::STATUS_REFUNDED]);

            return $return;
        });
    }

    /**
     * Process replacement path (generates a new sales order with returned lines).
     */
    public function processReplacement(SalesReturn $return, ?int $userId): SalesOrder
    {
        if ($return->status !== SalesReturn::STATUS_RECEIVED) {
            throw ValidationException::withMessages([
                'status' => ['Return items must be received before issuing a replacement order.'],
            ]);
        }

        return DB::transaction(function () use ($return, $userId) {
            $return->load(['lines.salesOrderLine']);

            $lines = [];
            foreach ($return->lines as $retLine) {
                $soLine = $retLine->salesOrderLine;
                $lines[] = [
                    'item_type' => $soLine ? $soLine->item_type : 'service',
                    'product_id' => $soLine ? $soLine->product_id : null,
                    'description' => ($soLine ? $soLine->description : 'Replacement item').' (Replacement for Return '.$return->uuid.')',
                    'qty_ordered' => (float) $retLine->qty_returned,
                    'unit_price' => $soLine ? (float) $soLine->unit_price : 0,
                    'discount_amount' => $soLine ? (float) $soLine->unit_price * (float) $retLine->qty_returned : 0, // 100% replacement discount
                    'tax_amount' => 0,
                ];
            }

            $newOrder = $this->salesOrderService->create([
                'customer_id' => $return->customer_id,
                'price_list_id' => $return->order ? $return->order->price_list_id : null,
                'subject_type' => 'sales.ret_hdrs',
                'subject_id' => $return->id,
                'lines' => $lines,
            ], $userId);

            $return->update([
                'status' => SalesReturn::STATUS_REPLACED,
                'replacement_so_id' => $newOrder->id,
            ]);

            return $newOrder;
        });
    }

    protected function recordCommissionReversals(SalesReturn $return): void
    {
        // Reversal logic: if original order has a creator/rep with a draft settlement
        $order = $return->order;
        if (! $order || ! $order->created_by) {
            return;
        }

        $repId = $order->created_by;
        $draftSettlement = CommissionSettlement::where('rep_id', $repId)
            ->where('status', CommissionSettlement::STATUS_DRAFT)
            ->first();

        if ($draftSettlement) {
            $plan = CommissionPlan::where('is_active', true)->first();
            if ($plan) {
                foreach ($return->lines as $retLine) {
                    $soLine = $retLine->salesOrderLine;
                    if ($soLine) {
                        $returnedValue = (float) $retLine->qty_returned * (float) $soLine->unit_price;
                        $rate = (float) ($plan->flat_rate_pct ?? 5.0);
                        $reversalAmount = round(($returnedValue * $rate) / 100, 2);

                        $draftSettlement->lines()->create([
                            'commission_plan_id' => $plan->id,
                            'so_line_id' => $soLine->id,
                            'line_type' => 'reversal',
                            'amount' => -$reversalAmount,
                            'notes' => 'Return reversal: '.$return->reason_code,
                        ]);

                        $draftSettlement->total_amount = (float) $draftSettlement->lines()->sum('amount');
                        $draftSettlement->save();
                    }
                }
            }
        }
    }
}
