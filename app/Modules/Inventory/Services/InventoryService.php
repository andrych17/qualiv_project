<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\GoodsIssue;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReservation;

/**
 * §5 "Internal facade" — the preferred integration point for other modules (Purchase's
 * Goods Receipt, Sales's Delivery Engine, or a vertical module like Legal tracking evidence
 * items), so they never touch `stock_ledger`/valuation directly. No compile-time dependency
 * in the other direction: Inventory doesn't know Purchase or Sales exist.
 *
 * `::transfer()`/`::adjust()` were never added as facade methods — Transfer/Adjustment ended
 * up with their own document lifecycle (draft/post, §3F/§3G) same as Receipt/Issue, so there
 * was nothing left for a thin pass-through to add; a caller wanting an immediate transfer/
 * adjustment uses `TransferService`/`AdjustmentService` directly, same shape as `receive()`/
 * `issue()` below but without the extra indirection. `::reserve()`/`::release()` land here now
 * with §3N (Sales isn't built yet, so still no real caller — same posture as §3D–§3M).
 */
class InventoryService
{
    public function __construct(
        protected GoodsReceiptService $receipts,
        protected GoodsIssueService $issues,
        protected ReservationService $reservations,
    ) {}

    /**
     * Creates and immediately posts a Goods Receipt in one call — skips the draft stage
     * the Inventory UI itself uses for manual entry. E.g. Purchase's Goods Receipt calls
     * this with `subject_type = 'purchase.pur_receipt_hdrs'` (§3D).
     *
     * @param  array<string, mixed>  $data  same shape as GoodsReceiptService::create()
     */
    public function receive(array $data): GoodsReceipt
    {
        return $this->receipts->post($this->receipts->create($data));
    }

    /**
     * Creates and immediately posts a Goods Issue in one call. E.g. Sales's Delivery
     * Engine calls this with `subject_type = 'sales.dlv_hdrs'` on ship-confirm (§3E).
     *
     * @param  array<string, mixed>  $data  same shape as GoodsIssueService::create()
     */
    public function issue(array $data): GoodsIssue
    {
        return $this->issues->post($this->issues->create($data));
    }

    /**
     * §3N: available-to-promise — `stock_balances.qty_on_hand` minus active reservations at
     * that product/location, per `INVENTORY_SPECS.md` §3N ("exposed as
     * `InventoryService::checkAvailability()`"). Physical on-hand (what a counter should
     * actually see on the shelf, unaffected by soft holds) is `onHandQty()` below — Adjustment's
     * system-qty hint uses that one, not this one.
     */
    public function checkAvailability(int $productId, int $warehouseId, ?int $locationId = null, ?int $batchId = null): float
    {
        return $this->onHandQty($productId, $warehouseId, $locationId, $batchId)
            - $this->reservations->activeReservedQty($productId, $warehouseId, $locationId, $batchId);
    }

    /** Raw physical on-hand quantity — no reservation subtraction. See `checkAvailability()` for the available-to-promise variant. */
    public function onHandQty(int $productId, int $warehouseId, ?int $locationId = null, ?int $batchId = null): float
    {
        return (float) StockBalance::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->when($batchId !== null, fn ($q) => $q->where('batch_id', $batchId))
            ->sum('qty_on_hand');
    }

    /** @param  array<string, mixed>  $data  same shape as ReservationService::reserve() */
    public function reserve(array $data): StockReservation
    {
        return $this->reservations->reserve($data);
    }

    public function release(StockReservation $reservation): void
    {
        $this->reservations->release($reservation);
    }
}
