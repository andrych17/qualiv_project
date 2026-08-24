<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3H: the payload shape Inventory's Goods Receipt engine (`INVENTORY_SPECS.md` §3D) will
 * dispatch once it exists, mirroring `inventory.goods_received`. Consumed by
 * App\Modules\Accounting\Listeners\PostGoodsReceivedToGl.
 *
 * No real caller exists yet — Inventory only has InventoryItem/InventoryCategory CRUD today,
 * not the Goods Receipt/Issue/Adjustment engine or `stock_ledger` this event describes. Same
 * "engine ships before its caller" precedent as §3D's InvoiceRequested — this event and its
 * listener are the seam §3H is written against.
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
