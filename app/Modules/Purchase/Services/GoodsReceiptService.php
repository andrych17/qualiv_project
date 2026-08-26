<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurOrderLine;
use App\Modules\Purchase\Models\PurReceiptHdr;
use App\Modules\Purchase\Models\PurReceiptLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptService
{
    public function generateGrNumber(): string
    {
        $prefix = 'GR-'.now()->format('Ym').'-';
        $maxAttempts = 50;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $latest = PurReceiptHdr::query()
                ->where('gr_no', 'like', "{$prefix}%")
                ->orderByDesc('gr_no')
                ->value('gr_no');

            $nextSeq = 1;
            if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
                $nextSeq = ((int) $m[1]) + 1;
            }

            $candidate = $prefix.sprintf('%04d', $nextSeq + $i);
            if (! PurReceiptHdr::query()->where('gr_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $prefix.substr(uniqid(), -4);
    }

    public function create(array $data, int $userId): PurReceiptHdr
    {
        $po = PurOrderHdr::with('lines')->findOrFail($data['po_id']);

        $allowedStatuses = [
            PurOrderHdr::STATUS_APPROVED,
            PurOrderHdr::STATUS_SENT,
            PurOrderHdr::STATUS_ACKNOWLEDGED,
            PurOrderHdr::STATUS_PARTIALLY_RECEIVED,
        ];

        if (! in_array($po->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'po_id' => ["Goods receipt cannot be recorded for PO in status '{$po->status}'."],
            ]);
        }

        return DB::transaction(function () use ($po, $data, $userId) {
            $grNo = $data['gr_no'] ?? $this->generateGrNumber();
            $receivedAt = $data['received_at'] ?? now();
            $receiverId = ! empty($data['receiver_id']) ? (int) $data['receiver_id'] : $userId;

            $receipt = PurReceiptHdr::create([
                'gr_no' => $grNo,
                'po_id' => $po->id,
                'receiver_id' => $receiverId,
                'received_at' => $receivedAt,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'status' => PurReceiptHdr::STATUS_POSTED,
                'discrepancy_notes' => $data['discrepancy_notes'] ?? null,
            ]);

            $lines = $data['lines'] ?? [];
            $poLinesMap = $po->lines->keyBy('id');

            foreach ($lines as $line) {
                $poLineId = (int) $line['po_line_id'];
                /** @var PurOrderLine|null $poLine */
                $poLine = $poLinesMap->get($poLineId);

                if (! $poLine) {
                    continue;
                }

                $qtyReceived = (float) ($line['quantity_received'] ?? 0);
                if ($qtyReceived <= 0) {
                    continue;
                }

                $newTotalReceived = (float) $poLine->qty_received + $qtyReceived;
                $isOverReceipt = $newTotalReceived > (float) $poLine->qty_ordered;

                $unitCost = array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null
                    ? (float) $line['unit_cost']
                    : (float) $poLine->unit_price;

                $receipt->lines()->create([
                    'po_line_id' => $poLine->id,
                    'quantity_received' => $qtyReceived,
                    'unit_cost' => $unitCost,
                    'condition_notes' => $line['condition_notes'] ?? null,
                    'over_receipt_flag' => $isOverReceipt,
                ]);

                // Update PO line received qty
                $poLine->qty_received = $newTotalReceived;
                $poLine->save();
            }

            // Update PO overall status based on fulfillment
            $po->refresh();
            $allFulfilled = true;
            $anyReceived = false;

            foreach ($po->lines as $l) {
                if ((float) $l->qty_received >= (float) $l->qty_ordered) {
                    $anyReceived = true;
                } elseif ((float) $l->qty_received > 0) {
                    $anyReceived = true;
                    $allFulfilled = false;
                } else {
                    $allFulfilled = false;
                }
            }

            if ($allFulfilled) {
                $po->status = PurOrderHdr::STATUS_RECEIVED;
            } elseif ($anyReceived) {
                $po->status = PurOrderHdr::STATUS_PARTIALLY_RECEIVED;
            }

            $po->save();

            return $receipt->fresh(['lines.poLine', 'order.supplier', 'receiver']);
        });
    }
}
