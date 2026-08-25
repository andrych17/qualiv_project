<?php

namespace App\Modules\Inventory\Services\Costing;

/**
 * §3J — the only thing that differs between FIFO and Weighted Average is *how consumption
 * is calculated on issue*, not the schema: both write to `stock_valuation_layers`.
 * Same driver pattern as WNE's ChannelDriverInterface / DMS's OcrDriverInterface (§5) — a
 * future strategy (Standard Cost) is additive, no core engine change.
 */
interface CostingStrategyInterface
{
    /**
     * Records a receipt of `$qty` (base UoM) at `$unitCost` (per base-UoM unit) as a new/updated
     * open layer. `$batchId` (§3L) scopes the layer to a lot — a batch-tracked product's layers
     * are segmented per batch (Average keeps one open layer per product/warehouse/batch, not
     * one per product/warehouse); null for a non-batch product, never mixed with batched layers.
     */
    public function costReceipt(int $productId, int $warehouseId, float $qty, float $unitCost, ?int $stockLedgerId, ?int $batchId = null): void;

    /**
     * Consumes `$qty` (base UoM) from open layers, row-locked for the duration of the
     * caller's transaction (§5 concurrency note) — throws if open layers can't cover it.
     * `$batchId` (§3L) restricts consumption to that lot's own layers only — a caller that
     * picked Batch B must not silently draw from Batch A's cheaper cost instead.
     *
     * @return array{unit_cost: float, total_value: float} weighted cost of this consumption
     */
    public function costIssue(int $productId, int $warehouseId, float $qty, ?int $batchId = null): array;

    /**
     * §3G: the cost basis a positive Adjustment's new layer is created at ("uses current
     * valuation layer cost") — FIFO's most recent open layer, or Average's one open layer,
     * scoped to `$batchId` (§3L) same as the other two methods. Returns 0 if no open layers
     * exist yet for this product/warehouse(/batch).
     */
    public function currentCost(int $productId, int $warehouseId, ?int $batchId = null): float;
}
