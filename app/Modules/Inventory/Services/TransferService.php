<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Inventory\Models\TransferLine;
use App\Modules\Inventory\Services\Costing\CostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3F Transfer — a paired issue-at-source + receipt-at-destination written atomically as
 * one `transfer` movement type (not two independent Goods Issue/Receipt documents), so the
 * cost basis moves with the stock unchanged (no Accounting event either — a transfer never
 * changes total company inventory value, only its location).
 */
class TransferService
{
    private const EPSILON = 0.0000005;

    public function __construct(
        protected CostingService $costing,
        protected StockBalanceService $balances,
        protected UomConversionResolver $uomResolver,
        protected SerialService $serials,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Transfer
    {
        return DB::transaction(function () use ($data) {
            $transfer = Transfer::query()->create([
                ...$this->headerAttributes($data),
                'status' => Transfer::STATUS_DRAFT,
                'created_by' => auth()->id(),
            ]);
            $this->syncLines($transfer, $data['lines'] ?? []);

            return $transfer->load('lines');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Transfer $transfer, array $data): Transfer
    {
        $this->assertDraft($transfer);

        return DB::transaction(function () use ($transfer, $data) {
            $transfer->update($this->headerAttributes($data));
            $this->syncLines($transfer, $data['lines'] ?? []);

            return $transfer->refresh()->load('lines');
        });
    }

    public function delete(Transfer $transfer): void
    {
        $this->assertDraft($transfer);
        $transfer->delete();
    }

    /**
     * §3F: on post, both ledger legs are written together in one transaction regardless of
     * status — `in_transit` vs `completed` is a tracking/workflow state layered on top for
     * cross-warehouse transfers with real transit time, it does not gate when the ledger
     * reflects the movement. Same-warehouse bin transfers land on `completed` immediately.
     */
    public function post(Transfer $transfer): Transfer
    {
        $this->assertDraft($transfer);

        if ($transfer->source_location_id === $transfer->destination_location_id) {
            throw ValidationException::withMessages(['destination_location_id' => 'Source and destination location can\'t be the same.']);
        }

        $lines = $transfer->lines()->with(['product', 'batch'])->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line before posting.']);
        }

        $lines = $lines->sortBy('product_id')->values();

        DB::transaction(function () use ($transfer, $lines) {
            foreach ($lines as $line) {
                $product = $line->product;

                if (! $product->is_active) {
                    throw ValidationException::withMessages(['lines' => "{$product->sku} is inactive and can't be transferred."]);
                }
                if ($product->tracking_mode === Product::TRACKING_BATCH && $line->batch_id === null) {
                    throw ValidationException::withMessages(['lines' => "{$product->sku} is batch-tracked — every line needs a lot selected before posting."]);
                }
                if ($product->tracking_mode === Product::TRACKING_SERIAL) {
                    $this->assertSerialCountMatchesQty($product, $line->serial_numbers ?? [], (float) $line->qty);
                }

                [$baseQty] = $this->uomResolver->toBaseUnits($product, $line->uom_id, (float) $line->qty, 0.0);

                $sourceBalance = $this->balances->lockOrCreate($product->id, $transfer->source_warehouse_id, $transfer->source_location_id, $line->batch_id);

                if ((float) $sourceBalance->qty_on_hand < $baseQty) {
                    $location = Location::query()->find($transfer->source_location_id);
                    $lot = $line->batch_id ? " (lot {$line->batch->batch_number})" : '';
                    throw ValidationException::withMessages([
                        'lines' => "Only {$sourceBalance->qty_on_hand} units of {$product->sku}{$lot} available at {$location?->code} — reduce quantity or choose another location/lot.",
                    ]);
                }

                $destinationBalance = $this->balances->lockOrCreate($product->id, $transfer->destination_warehouse_id, $transfer->destination_location_id, $line->batch_id);

                // Cost basis moves with the stock (§3F) — whatever cost is consumed at the
                // source becomes the cost the destination's new layer is created at, no
                // re-pricing gain/loss, same as a receipt+issue pair at an identical price.
                // §3L: the batch_id rides along identically — a lot number doesn't change
                // because the pallet moved, so the destination never selects/creates its own.
                $strategy = $this->costing->strategyFor($product);
                $consumption = $strategy->costIssue($product->id, $transfer->source_warehouse_id, $baseQty, $line->batch_id);

                StockLedger::query()->create([
                    'product_id' => $product->id,
                    'warehouse_id' => $transfer->source_warehouse_id,
                    'location_id' => $transfer->source_location_id,
                    'batch_id' => $line->batch_id,
                    'movement_type' => StockLedger::TYPE_TRANSFER,
                    'qty' => -$baseQty,
                    'unit_cost' => $consumption['unit_cost'],
                    'total_value' => -$consumption['total_value'],
                    'subject_type' => 'inventory.transfers',
                    'subject_id' => (string) $transfer->id,
                    'movement_date' => $transfer->transfer_date,
                    'created_by' => auth()->id(),
                ]);
                $this->balances->applyDelta($sourceBalance, -$baseQty);

                $inLedger = StockLedger::query()->create([
                    'product_id' => $product->id,
                    'warehouse_id' => $transfer->destination_warehouse_id,
                    'location_id' => $transfer->destination_location_id,
                    'batch_id' => $line->batch_id,
                    'movement_type' => StockLedger::TYPE_TRANSFER,
                    'qty' => $baseQty,
                    'unit_cost' => $consumption['unit_cost'],
                    'total_value' => $consumption['total_value'],
                    'subject_type' => 'inventory.transfers',
                    'subject_id' => (string) $transfer->id,
                    'movement_date' => $transfer->transfer_date,
                    'created_by' => auth()->id(),
                ]);
                $strategy->costReceipt($product->id, $transfer->destination_warehouse_id, $baseQty, $consumption['unit_cost'], $inLedger->id, $line->batch_id);
                $this->balances->applyDelta($destinationBalance, $baseQty);

                if ($product->tracking_mode === Product::TRACKING_SERIAL) {
                    $this->serials->transfer(
                        $product->id, $line->serial_numbers ?? [],
                        $transfer->source_warehouse_id, $transfer->source_location_id,
                        $transfer->destination_warehouse_id, $transfer->destination_location_id,
                        $inLedger->id,
                    );
                }
            }

            $sameWarehouse = $transfer->source_warehouse_id === $transfer->destination_warehouse_id;

            $transfer->update([
                'status' => $sameWarehouse ? Transfer::STATUS_COMPLETED : Transfer::STATUS_IN_TRANSIT,
                'posted_at' => now(),
                'completed_at' => $sameWarehouse ? now() : null,
            ]);
        });

        return $transfer->refresh()->load('lines');
    }

    /** §3F: the physical-receipt confirmation for a cross-warehouse transfer — no ledger effect, the movement already posted. */
    public function complete(Transfer $transfer): Transfer
    {
        if ($transfer->status !== Transfer::STATUS_IN_TRANSIT) {
            throw ValidationException::withMessages(['status' => 'Only an in-transit transfer can be marked completed.']);
        }

        $transfer->update(['status' => Transfer::STATUS_COMPLETED, 'completed_at' => now()]);

        return $transfer->refresh();
    }

    private function assertDraft(Transfer $transfer): void
    {
        if ($transfer->status !== Transfer::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'This transfer is already posted and can no longer be edited.']);
        }
    }

    /** §3M — see GoodsReceiptService::assertSerialCountMatchesQty(); same rule for a transfer line. @param  array<int, mixed>  $serialNumbers */
    private function assertSerialCountMatchesQty(Product $product, array $serialNumbers, float $qty): void
    {
        if (abs($qty - round($qty)) > self::EPSILON) {
            throw ValidationException::withMessages(['lines' => "{$product->sku} is serial-tracked — quantity must be a whole number."]);
        }
        if (abs(count($serialNumbers) - $qty) > self::EPSILON) {
            throw ValidationException::withMessages(['lines' => "{$product->sku} is serial-tracked — name exactly {$qty} serial(s) to transfer, one per unit."]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function headerAttributes(array $data): array
    {
        return [
            'source_warehouse_id' => $data['source_warehouse_id'],
            'source_location_id' => $data['source_location_id'],
            'destination_warehouse_id' => $data['destination_warehouse_id'],
            'destination_location_id' => $data['destination_location_id'],
            'transfer_date' => $data['transfer_date'],
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncLines(Transfer $transfer, array $lines): void
    {
        $transfer->lines()->delete();

        foreach ($lines as $line) {
            if (empty($line['product_id']) || empty($line['qty'])) {
                continue;
            }

            TransferLine::query()->create([
                'transfer_id' => $transfer->id,
                'product_id' => $line['product_id'],
                'batch_id' => $line['batch_id'] ?? null,
                'qty' => $line['qty'],
                'uom_id' => $line['uom_id'],
                'serial_numbers' => ! empty($line['serial_numbers']) ? array_values(array_filter($line['serial_numbers'])) : null,
            ]);
        }
    }
}
