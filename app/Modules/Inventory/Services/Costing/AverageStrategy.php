<?php

namespace App\Modules\Inventory\Services\Costing;

use App\Modules\Inventory\Models\StockValuationLayer;
use Illuminate\Validation\ValidationException;

/**
 * §3J Weighted Average — exactly one open layer per product/warehouse, re-priced on every
 * receipt (`new_avg = (old_qty*old_avg + received_qty*received_cost) / (old_qty+received_qty)`).
 * Issues consume from it at the current average and never change that average themselves —
 * so the layer is mutated in place rather than a new one created per receipt (append-only
 * immutability is `stock_ledger`'s rule, not this working/cache table's).
 */
class AverageStrategy implements CostingStrategyInterface
{
    private const EPSILON = 0.0000005;

    public function costReceipt(int $productId, int $warehouseId, float $qty, float $unitCost, ?int $stockLedgerId, ?int $batchId = null): void
    {
        $layer = StockValuationLayer::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($batchId, fn ($q) => $q->where('batch_id', $batchId), fn ($q) => $q->whereNull('batch_id'))
            ->lockForUpdate()
            ->first();

        if (! $layer) {
            StockValuationLayer::query()->create([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'batch_id' => $batchId,
                'stock_ledger_id' => $stockLedgerId,
                'unit_cost' => $unitCost,
                'qty' => $qty,
                'remaining_qty' => $qty,
            ]);

            return;
        }

        $oldQty = (float) $layer->remaining_qty;
        $oldCost = (float) $layer->unit_cost;
        $newQty = $oldQty + $qty;

        $layer->unit_cost = $newQty > 0 ? (($oldQty * $oldCost) + ($qty * $unitCost)) / $newQty : $unitCost;
        $layer->remaining_qty = $newQty;
        $layer->qty = (float) $layer->qty + $qty;
        $layer->stock_ledger_id = $stockLedgerId;
        $layer->save();
    }

    public function costIssue(int $productId, int $warehouseId, float $qty, ?int $batchId = null): array
    {
        $layer = StockValuationLayer::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($batchId, fn ($q) => $q->where('batch_id', $batchId), fn ($q) => $q->whereNull('batch_id'))
            ->lockForUpdate()
            ->first();

        if (! $layer || (float) $layer->remaining_qty < $qty - self::EPSILON) {
            throw ValidationException::withMessages([
                'lines' => 'Not enough open cost layers to cover this issue — on-hand and valuation data have drifted out of sync.',
            ]);
        }

        $unitCost = (float) $layer->unit_cost;
        $layer->remaining_qty = (float) $layer->remaining_qty - $qty;
        $layer->save();

        return [
            'unit_cost' => $unitCost,
            'total_value' => $qty * $unitCost,
        ];
    }

    public function currentCost(int $productId, int $warehouseId, ?int $batchId = null): float
    {
        return (float) (StockValuationLayer::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($batchId, fn ($q) => $q->where('batch_id', $batchId), fn ($q) => $q->whereNull('batch_id'))
            ->value('unit_cost') ?? 0.0);
    }
}
