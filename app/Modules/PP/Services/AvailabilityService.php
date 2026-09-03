<?php

namespace App\Modules\PP\Services;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReservation;

/**
 * PP_SPECS.md §3B/§3D — available-to-promise summed across every warehouse, a planning-level
 * read shared by the safety-stock check (§3B) and the MRP engine's netting formula (§3D).
 * Unlike `InventoryService::checkAvailability()`, this is deliberately not warehouse-scoped —
 * planning nets against total company-wide stock, not one location.
 */
class AvailabilityService
{
    public function totalAvailableQty(int $productId): float
    {
        $onHand = (float) StockBalance::query()->where('product_id', $productId)->sum('qty_on_hand');
        $reserved = (float) StockReservation::query()
            ->where('product_id', $productId)
            ->where('status', StockReservation::STATUS_ACTIVE)
            ->sum('qty');

        return $onHand - $reserved;
    }
}
