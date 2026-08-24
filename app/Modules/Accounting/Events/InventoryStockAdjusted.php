<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3H: the payload shape Inventory's Adjustment engine (`INVENTORY_SPECS.md` §3G) will
 * dispatch once it exists, mirroring `inventory.stock_adjusted`. Consumed by
 * App\Modules\Accounting\Listeners\PostStockAdjustmentToGl.
 *
 * No real caller exists yet — see InventoryGoodsReceived's docblock for the full "engine
 * ships before its caller" rationale, identical here.
 *
 * `quantity`/`totalValue` are SIGNED by the adjustment's direction: positive = a write-up
 * (found stock, correction that increases value), negative = a write-down (damage, loss,
 * shrinkage). The listener posts to whichever side of the mapped adjustment account the
 * sign calls for — never a fixed debit or credit.
 */
class InventoryStockAdjusted
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
