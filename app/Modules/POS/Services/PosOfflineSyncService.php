<?php

namespace App\Modules\POS\Services;

use App\Modules\POS\Models\PosPayment;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\POS\Models\PosTxnLine;
use App\Modules\POS\Models\PosTxnLineModifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS_SPECS.md §3S — Offline-First Architecture & Idempotent Sync Queue.
 */
class PosOfflineSyncService
{
    public function __construct(
        protected PosPostingService $postingService,
    ) {}

    /**
     * Syncs an offline-generated transaction payload idempotently.
     */
    public function syncTransaction(array $payload, int $terminalId): array
    {
        $clientTxnUuid = $payload['client_txn_uuid'] ?? null;
        if (! $clientTxnUuid) {
            throw ValidationException::withMessages(['client_txn_uuid' => ['client_txn_uuid is required for sync.']]);
        }

        // 1. Idempotency Check: if already processed, return existing
        $existing = PosTxnHdr::query()->where('client_txn_uuid', $clientTxnUuid)->first();
        if ($existing) {
            return [
                'status' => 'already_synced',
                'message' => 'Transaction has already been synced.',
                'transaction' => $existing->load(['lines', 'payments']),
            ];
        }

        return DB::transaction(function () use ($payload, $terminalId, $clientTxnUuid) {
            $terminal = PosTerminal::query()->findOrFail($terminalId);

            $sessionId = $payload['session_id'];
            $receiptNumber = $payload['receipt_number'] ?? "{$terminal->receipt_prefix}-OFFLINE-".substr($clientTxnUuid, 0, 6);

            $occurredAt = $payload['occurred_at'] ?? now();
            $subtotal = (float) ($payload['subtotal'] ?? 0);
            $discountTotal = (float) ($payload['discount_total'] ?? 0);
            $taxTotal = (float) ($payload['tax_total'] ?? 0);
            $grandTotal = (float) ($payload['grand_total'] ?? ($subtotal - $discountTotal + $taxTotal));

            $txn = PosTxnHdr::query()->create([
                'client_txn_uuid' => $clientTxnUuid,
                'session_id' => $sessionId,
                'terminal_id' => $terminalId,
                'receipt_number' => $receiptNumber,
                'customer_id' => $payload['customer_id'] ?? null,
                'dining_mode' => $payload['dining_mode'] ?? PosTxnHdr::DINING_SALE,
                'status' => PosTxnHdr::STATUS_COMPLETED,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'created_offline' => true,
                'occurred_at' => $occurredAt,
                'synced_at' => now(),
                'notes' => $payload['notes'] ?? null,
            ]);

            // Create lines
            $lines = $payload['lines'] ?? [];
            foreach ($lines as $idx => $lineData) {
                $line = PosTxnLine::query()->create([
                    'txn_id' => $txn->id,
                    'line_no' => $idx + 1,
                    'product_id' => $lineData['product_id'] ?? null,
                    'is_open_item' => (bool) ($lineData['is_open_item'] ?? false),
                    'description' => $lineData['description'] ?? 'Item',
                    'uom_code' => $lineData['uom_code'] ?? 'EA',
                    'qty' => $lineData['qty'] ?? 1,
                    'unit_price' => $lineData['unit_price'] ?? 0,
                    'discount_amount' => $lineData['discount_amount'] ?? 0,
                    'tax_amount' => $lineData['tax_amount'] ?? 0,
                    'line_total' => $lineData['line_total'] ?? (($lineData['qty'] ?? 1) * ($lineData['unit_price'] ?? 0)),
                ]);

                if (! empty($lineData['modifiers'])) {
                    foreach ($lineData['modifiers'] as $mod) {
                        PosTxnLineModifier::query()->create([
                            'txn_line_id' => $line->id,
                            'modifier_id' => $mod['modifier_id'],
                            'modifier_name' => $mod['modifier_name'] ?? 'Modifier',
                            'price_delta' => $mod['price_delta'] ?? 0,
                        ]);
                    }
                }
            }

            // Create payments
            $payments = $payload['payments'] ?? [];
            foreach ($payments as $pmt) {
                PosPayment::query()->create([
                    'txn_id' => $txn->id,
                    'method' => $pmt['method'] ?? 'cash',
                    'amount' => $pmt['amount'] ?? $grandTotal,
                    'reference' => $pmt['reference'] ?? null,
                    'change_given' => $pmt['change_given'] ?? 0,
                    'occurred_at' => $occurredAt,
                ]);
            }

            // Post inventory and accounting
            $this->postingService->postToInventory($txn);
            $this->postingService->postToAccounting($txn);

            return [
                'status' => 'synced',
                'message' => 'Transaction successfully synced.',
                'transaction' => $txn->refresh()->load(['lines', 'payments']),
            ];
        });
    }
}
