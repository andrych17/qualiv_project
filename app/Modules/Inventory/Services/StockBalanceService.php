<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockBalance;
use Illuminate\Support\Facades\DB;

/**
 * §5 concurrency note: posting must lock the relevant `stock_balances` row before reading
 * it, so two concurrent issues against the same bin can't both pass the availability check
 * against a stale quantity. The insert-if-absent step is a plain atomic upsert (safe under
 * race via the table's unique index) so a first-ever movement into a bin doesn't need a
 * separate create-then-lock round trip that could itself race.
 */
class StockBalanceService
{
    /**
     * §3L: `$batchId` is part of the row's identity — a bin holding two batches of the same
     * product has two independent balance rows. The upsert target is the
     * `COALESCE(batch_id, 0)` expression index (see the batch-tracking migration's docblock)
     * since Postgres would otherwise never treat two NULL batch_ids as conflicting.
     */
    public function lockOrCreate(int $productId, int $warehouseId, int $locationId, ?int $batchId = null): StockBalance
    {
        DB::statement(
            'INSERT INTO "INVENTORY".stock_balances (product_id, warehouse_id, location_id, batch_id, qty_on_hand, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, now(), now())
             ON CONFLICT (product_id, warehouse_id, location_id, (COALESCE(batch_id, 0))) DO NOTHING',
            [$productId, $warehouseId, $locationId, $batchId],
        );

        return StockBalance::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('location_id', $locationId)
            ->when($batchId, fn ($q) => $q->where('batch_id', $batchId), fn ($q) => $q->whereNull('batch_id'))
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param  float  $delta  Signed — positive for a receipt, negative for an issue. */
    public function applyDelta(StockBalance $balance, float $delta): void
    {
        $balance->qty_on_hand = (float) $balance->qty_on_hand + $delta;
        $balance->save();
    }
}
