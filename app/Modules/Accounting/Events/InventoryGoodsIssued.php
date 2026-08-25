<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3H: the payload shape Inventory's Goods Issue engine (`INVENTORY_SPECS.md` §3E)
 * dispatches on `GoodsIssueService::post()`, mirroring `inventory.goods_issued`. Consumed by
 * App\Modules\Accounting\Listeners\PostGoodsIssuedToGl.
 *
 * `inventoryItemId` is `App\Modules\Inventory\Models\Product::id` — see InventoryGoodsReceived's
 * docblock for the full identity/mapping-fallback rationale, identical here.
 *
 * `totalValue` is the COGS value of the issued stock as Inventory's costing method (FIFO
 * layer consumption or weighted-average) already computed it — never recalculated here.
 */
class InventoryGoodsIssued
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
