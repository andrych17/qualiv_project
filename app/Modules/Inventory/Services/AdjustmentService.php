<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Accounting\Events\InventoryStockAdjusted;
use App\Modules\Inventory\Models\Adjustment;
use App\Modules\Inventory\Models\AdjustmentLine;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Services\Costing\CostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** §3G Adjustment — draft CRUD plus the one action that touches the ledger: post(). */
class AdjustmentService
{
    private const EPSILON = 0.0000005;

    public function __construct(
        protected CostingService $costing,
        protected StockBalanceService $balances,
        protected AccountingCompanyResolver $companyResolver,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Adjustment
    {
        return DB::transaction(function () use ($data) {
            $adjustment = Adjustment::query()->create([
                ...$this->headerAttributes($data),
                'status' => Adjustment::STATUS_DRAFT,
                'created_by' => auth()->id(),
            ]);
            $this->syncLines($adjustment, $data['lines'] ?? []);

            return $adjustment->load('lines');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Adjustment $adjustment, array $data): Adjustment
    {
        $this->assertDraft($adjustment);

        return DB::transaction(function () use ($adjustment, $data) {
            $adjustment->update($this->headerAttributes($data));
            $this->syncLines($adjustment, $data['lines'] ?? []);

            return $adjustment->refresh()->load('lines');
        });
    }

    public function delete(Adjustment $adjustment): void
    {
        $this->assertDraft($adjustment);
        $adjustment->delete();
    }

    /**
     * §3G: variance is always computed against the LIVE `stock_balances` quantity at post
     * time, never the line's stored `system_qty` snapshot (which may have drifted since the
     * draft was saved) — correcting against stale data would post the wrong delta. A
     * positive variance creates/tops-up a layer at the product's current cost; a negative
     * variance consumes layers exactly like a Goods Issue. Zero-variance lines are skipped
     * (a clean count needs no ledger entry).
     */
    public function post(Adjustment $adjustment): Adjustment
    {
        $this->assertDraft($adjustment);

        $lines = $adjustment->lines()->with('product', 'batch')->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line before posting.']);
        }

        $this->assertLocationInWarehouse($adjustment->location_id, $adjustment->warehouse_id);

        $lines = $lines->sortBy('product_id')->values();

        $ledgerRows = DB::transaction(function () use ($adjustment, $lines) {
            $rows = [];

            foreach ($lines as $line) {
                $product = $line->product;

                if (! $product->is_active) {
                    throw ValidationException::withMessages(['lines' => "{$product->sku} is inactive and can't be adjusted."]);
                }
                if ($product->tracking_mode === Product::TRACKING_BATCH && $line->batch_id === null) {
                    throw ValidationException::withMessages(['lines' => "{$product->sku} is batch-tracked — every line needs a lot selected before posting."]);
                }
                // §3M: unlike batch-tracked lines (which adjust fine — a lot's quantity is
                // just a number), a serial-tracked variance would move `stock_balances`
                // without touching `stock_serials`, silently desyncing the two with nothing
                // to detect it. Adjustment doesn't know which specific unit was found/lost,
                // so it's blocked entirely for now — correct serialized stock via a Receipt
                // (found) or Issue (lost/scrapped) instead, both of which do know the serial.
                if ($product->tracking_mode === Product::TRACKING_SERIAL) {
                    throw ValidationException::withMessages(['lines' => "{$product->sku} is serial-tracked — adjustments aren't supported yet, use a Receipt or Issue naming the specific serial instead."]);
                }

                $balance = $this->balances->lockOrCreate($product->id, $adjustment->warehouse_id, $adjustment->location_id, $line->batch_id);
                $variance = (float) $line->counted_qty - (float) $balance->qty_on_hand;

                if (abs($variance) <= self::EPSILON) {
                    continue;
                }

                $strategy = $this->costing->strategyFor($product);

                if ($variance > 0) {
                    $unitCost = $strategy->currentCost($product->id, $adjustment->warehouse_id, $line->batch_id);
                    $totalValue = $variance * $unitCost;
                } else {
                    $consumption = $strategy->costIssue($product->id, $adjustment->warehouse_id, abs($variance), $line->batch_id);
                    $unitCost = $consumption['unit_cost'];
                    $totalValue = -$consumption['total_value'];
                }

                $ledger = StockLedger::query()->create([
                    'product_id' => $product->id,
                    'warehouse_id' => $adjustment->warehouse_id,
                    'location_id' => $adjustment->location_id,
                    'batch_id' => $line->batch_id,
                    'movement_type' => StockLedger::TYPE_ADJUSTMENT,
                    'qty' => $variance,
                    'unit_cost' => $unitCost,
                    'total_value' => $totalValue,
                    'subject_type' => 'inventory.adjustments',
                    'subject_id' => (string) $adjustment->id,
                    'movement_date' => $adjustment->adjustment_date,
                    'created_by' => auth()->id(),
                ]);

                if ($variance > 0) {
                    $strategy->costReceipt($product->id, $adjustment->warehouse_id, $variance, $unitCost, $ledger->id, $line->batch_id);
                }

                $this->balances->applyDelta($balance, $variance);

                $rows[] = $ledger;
            }

            $adjustment->update(['status' => Adjustment::STATUS_POSTED, 'posted_at' => now()]);

            return $rows;
        });

        $this->dispatchAccountingEvents($ledgerRows);

        return $adjustment->refresh()->load('lines');
    }

    /** @param  list<StockLedger>  $ledgerRows */
    private function dispatchAccountingEvents(array $ledgerRows): void
    {
        $companyId = $this->companyResolver->resolve();
        if ($companyId === null) {
            return;
        }

        foreach ($ledgerRows as $ledger) {
            InventoryStockAdjusted::dispatch(
                $companyId,
                $ledger->product_id,
                (float) $ledger->qty,
                (float) $ledger->unit_cost,
                (float) $ledger->total_value,
                $ledger->movement_date->toDateString(),
                'inventory.stock_ledger',
                (string) $ledger->id,
            );
        }
    }

    private function assertDraft(Adjustment $adjustment): void
    {
        if ($adjustment->status !== Adjustment::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'This adjustment is already posted and can no longer be edited.']);
        }
    }

    private function assertLocationInWarehouse(int $locationId, int $warehouseId): void
    {
        $belongs = Location::query()->where('id', $locationId)->where('warehouse_id', $warehouseId)->exists();
        if (! $belongs) {
            throw ValidationException::withMessages(['location_id' => 'The location does not belong to this adjustment\'s warehouse.']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function headerAttributes(array $data): array
    {
        return [
            'warehouse_id' => $data['warehouse_id'],
            'location_id' => $data['location_id'],
            'adjustment_date' => $data['adjustment_date'],
            'reason_id' => $data['reason_id'],
            'reference' => $data['reference'] ?? null,
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncLines(Adjustment $adjustment, array $lines): void
    {
        $adjustment->lines()->delete();

        foreach ($lines as $line) {
            if (empty($line['product_id']) || ! isset($line['counted_qty'])) {
                continue;
            }

            AdjustmentLine::query()->create([
                'adjustment_id' => $adjustment->id,
                'product_id' => $line['product_id'],
                'batch_id' => $line['batch_id'] ?? null,
                'system_qty' => $line['system_qty'] ?? null,
                'counted_qty' => $line['counted_qty'],
            ]);
        }
    }
}
