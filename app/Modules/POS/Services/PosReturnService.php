<?php

namespace App\Modules\POS\Services;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\POS\Models\PosOverrideLog;
use App\Modules\POS\Models\PosReturnHdr;
use App\Modules\POS\Models\PosReturnLine;
use App\Modules\POS\Models\PosStoreCredit;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\POS\Models\PosTxnLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * POS_SPECS.md §3L — POS Returns & Refunds with Automatic Stock & Financial Reversal.
 */
class PosReturnService
{
    public function __construct(
        protected PosSupervisorService $supervisorService,
    ) {}

    public function processReturn(
        int $originalTxnId,
        int $sessionId,
        string $reasonCode,
        array $lines,
        ?string $refundMethod = 'cash',
        bool $withoutReceipt = false,
        ?string $supervisorPin = null,
        ?int $approvedByUserId = null
    ): PosReturnHdr {
        return DB::transaction(function () use (
            $originalTxnId,
            $sessionId,
            $reasonCode,
            $lines,
            $refundMethod,
            $withoutReceipt,
            $supervisorPin,
            $approvedByUserId
        ) {
            $originalTxn = PosTxnHdr::query()->with('terminal')->findOrFail($originalTxnId);

            if ($originalTxn->status !== PosTxnHdr::STATUS_COMPLETED && ! $withoutReceipt) {
                throw ValidationException::withMessages([
                    'original_txn_id' => ['Return requires a completed original transaction.'],
                ]);
            }

            $approvedBy = $approvedByUserId;
            if ($withoutReceipt) {
                if ($supervisorPin) {
                    $approvedBy = $this->supervisorService->verifyPinAndGetUserId($supervisorPin);
                }
                if (! $approvedBy) {
                    throw ValidationException::withMessages([
                        'supervisor_pin' => ['Return without receipt requires supervisor PIN approval.'],
                    ]);
                }
                $this->supervisorService->recordOverride(
                    auth()->id() ?: 1,
                    $approvedBy,
                    PosOverrideLog::ACTION_REFUND,
                    $originalTxn->id,
                    $sessionId,
                    "Return without receipt for txn #{$originalTxn->id}"
                );
            }

            $returnHdr = PosReturnHdr::query()->create([
                'original_txn_id' => $originalTxn->id,
                'session_id' => $sessionId,
                'reason_code' => $reasonCode,
                'status' => PosReturnHdr::STATUS_REQUESTED,
                'refund_method' => $refundMethod,
                'without_receipt' => $withoutReceipt,
                'approved_by' => $approvedBy,
            ]);

            $totalRefundAmount = 0.0;
            $restockLines = [];
            $terminal = $originalTxn->terminal;
            $defaultLocation = Location::query()
                ->where('warehouse_id', $terminal->warehouse_id)
                ->where('is_active', true)
                ->first();

            foreach ($lines as $lineData) {
                $origLineId = $lineData['original_txn_line_id'] ?? null;
                $origLine = $origLineId ? PosTxnLine::query()->find($origLineId) : null;

                $qty = (float) $lineData['qty'];
                $unitPrice = (float) ($lineData['unit_price'] ?? ($origLine?->unit_price ?? 0));
                $restockable = (bool) ($lineData['restockable'] ?? true);
                $conditionNote = $lineData['condition_note'] ?? null;

                $lineTotal = $qty * $unitPrice;
                $totalRefundAmount += $lineTotal;

                PosReturnLine::query()->create([
                    'return_id' => $returnHdr->id,
                    'original_txn_line_id' => $origLineId,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'condition_note' => $conditionNote,
                    'restockable' => $restockable,
                ]);

                if ($restockable && $origLine && $origLine->product_id) {
                    $product = Product::query()->find($origLine->product_id);
                    if ($product) {
                        $restockLines[] = [
                            'product_id' => $product->id,
                            'qty' => $qty,
                            'uom_id' => $product->base_uom_id,
                            'source_location_id' => $defaultLocation?->id,
                            'batch_id' => $origLine->batch_id,
                        ];
                    }
                }
            }

            // Automatic stock reversal (§3L, §12)
            if (! empty($restockLines) && app()->bound(InventoryService::class)) {
                try {
                    app(InventoryService::class)->receive([
                        'warehouse_id' => $terminal->warehouse_id,
                        'receipt_date' => now()->toDateString(),
                        'reason' => 'return',
                        'subject_type' => 'pos.pos_return_hdrs',
                        'subject_id' => $returnHdr->id,
                        'lines' => $restockLines,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("POS return stock reversal deferred/warning: ".$e->getMessage());
                }
            }

            // Store credit refund method (§3R)
            if ($refundMethod === 'store_credit' && $originalTxn->customer_id) {
                PosStoreCredit::query()->create([
                    'customer_id' => $originalTxn->customer_id,
                    'balance' => $totalRefundAmount,
                    'source_type' => 'pos.pos_return_hdrs',
                    'source_id' => $returnHdr->id,
                    'created_at' => now(),
                ]);
            }

            $returnHdr->update(['status' => PosReturnHdr::STATUS_COMPLETED]);

            return $returnHdr->refresh()->load('lines');
        });
    }
}
