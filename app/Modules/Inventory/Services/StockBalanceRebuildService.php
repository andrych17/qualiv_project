<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * §3H integrity safety net: "stock_balances is a cache for fast reads, never the source of
 * truth ... a rebuild job can regenerate balances from the ledger if they ever drift."
 * Sums `stock_ledger.qty` (signed) grouped by product/warehouse/location — that's the whole
 * algorithm, since the ledger is append-only and every posting engine (§3D-§3G) already
 * writes a row per movement.
 */
class StockBalanceRebuildService
{
    /** @return int number of (product, warehouse, location) balance rows rebuilt */
    public function rebuild(?int $productId = null): int
    {
        // §3L: batch_id is part of the balance grain (see the batch-tracking migration) —
        // grouping without it would merge separate batches' ledger history into one row.
        $sums = StockLedger::query()
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->selectRaw('product_id, warehouse_id, location_id, batch_id, SUM(qty) as qty_on_hand')
            ->groupBy('product_id', 'warehouse_id', 'location_id', 'batch_id')
            ->get();

        DB::transaction(function () use ($sums, $productId) {
            StockBalance::query()->when($productId, fn ($q) => $q->where('product_id', $productId))->delete();

            foreach ($sums as $row) {
                StockBalance::query()->create([
                    'product_id' => $row->product_id,
                    'warehouse_id' => $row->warehouse_id,
                    'location_id' => $row->location_id,
                    'batch_id' => $row->batch_id,
                    'qty_on_hand' => $row->qty_on_hand,
                ]);
            }
        });

        return $sums->count();
    }

    /** Live on-hand per the ledger vs. the cached balance — for a drift check on the Stock Card. */
    public function ledgerTotal(int $productId, ?int $warehouseId = null, ?int $locationId = null): float
    {
        return (float) StockLedger::query()
            ->where('product_id', $productId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->sum('qty');
    }
}
