<?php

namespace App\Modules\Inventory\Services\Costing;

use App\Modules\Inventory\Models\StockValuationLayer;
use Illuminate\Validation\ValidationException;

/** §3J FIFO — discrete layers per receipt, consumed oldest-first on issue. */
class FifoStrategy implements CostingStrategyInterface
{
    private const EPSILON = 0.0000005;

    public function costReceipt(int $productId, int $warehouseId, float $qty, float $unitCost, ?int $stockLedgerId, ?int $batchId = null): void
    {
        StockValuationLayer::query()->create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'batch_id' => $batchId,
            'stock_ledger_id' => $stockLedgerId,
            'unit_cost' => $unitCost,
            'qty' => $qty,
            'remaining_qty' => $qty,
        ]);
    }

    public function costIssue(int $productId, int $warehouseId, float $qty, ?int $batchId = null): array
    {
        $layers = StockValuationLayer::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($batchId, fn ($q) => $q->where('batch_id', $batchId), fn ($q) => $q->whereNull('batch_id'))
            ->where('remaining_qty', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $qty;
        $totalValue = 0.0;
        $consumedQty = 0.0;

        foreach ($layers as $layer) {
            if ($remaining <= self::EPSILON) {
                break;
            }

            $take = min($remaining, (float) $layer->remaining_qty);
            $totalValue += $take * (float) $layer->unit_cost;
            $layer->remaining_qty = (float) $layer->remaining_qty - $take;
            $layer->save();

            $remaining -= $take;
            $consumedQty += $take;
        }

        if ($remaining > self::EPSILON) {
            throw ValidationException::withMessages([
                'lines' => 'Not enough open cost layers to cover this issue — on-hand and valuation data have drifted out of sync.',
            ]);
        }

        return [
            'unit_cost' => $consumedQty > 0 ? $totalValue / $consumedQty : 0.0,
            'total_value' => $totalValue,
        ];
    }

    public function currentCost(int $productId, int $warehouseId, ?int $batchId = null): float
    {
        return (float) (StockValuationLayer::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($batchId, fn ($q) => $q->where('batch_id', $batchId), fn ($q) => $q->whereNull('batch_id'))
            ->where('remaining_qty', '>', 0)
            ->orderByDesc('id')
            ->value('unit_cost') ?? 0.0);
    }
}
