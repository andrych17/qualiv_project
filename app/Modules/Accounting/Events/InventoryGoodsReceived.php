<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3H: the payload shape Inventory's Goods Receipt engine (`INVENTORY_SPECS.md` §3D)
 * dispatches on `GoodsReceiptService::post()`, mirroring `inventory.goods_received`.
 * Consumed by App\Modules\Accounting\Listeners\PostGoodsReceivedToGl.
 *
 * `inventoryItemId` is `App\Modules\Inventory\Models\Product::id` (INVENTORY.products) — the
 * real Inventory engine's identity, not the legacy public-schema `inventory_items` demo table
 * (CLAUDE.md §7A). `InventoryGlPostingService::resolveMapping()`'s category fallback reads
 * `Product::category_id` accordingly.
 *
 * `unitCost`/`totalValue` are always a figure Inventory has already computed (its own
 * `CostingStrategyInterface`) — this engine holds zero costing logic and never recalculates
 * either (§3H rule). `subjectType`/`subjectId` point back to the originating `stock_ledger`
 * row for traceability and are also this listener's idempotency key.
 */
class InventoryGoodsReceived
{
    use Dispatchable;

    public function __construct(
        public int $companyId,
        public int $inventoryItemId,
        public float $quantity,
        public float $unitCost,
        public float $totalValue,
        public string $movementDate,
        public string $subjectType,
        public string $subjectId,
        public ?string $memo = null,
    ) {}
}
