<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\SysConfig\Services\ConfigService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3N — the reservation engine. `InventoryService::reserve()`/`::release()` are the facade
 * other modules call (Sales order-confirm, not built yet — same "engine ships before caller"
 * posture as §3D–§3M); this service holds the actual logic plus `activeReservedQty()`, which
 * `InventoryService::checkAvailability()` subtracts from on-hand.
 */
class ReservationService
{
    private const EPSILON = 0.0000005;

    public function __construct(protected ConfigService $config) {}

    /** @param  array<string, mixed>  $data */
    public function reserve(array $data): StockReservation
    {
        return DB::transaction(function () use ($data) {
            $productId = (int) $data['product_id'];
            $product = Product::query()->findOrFail($productId);

            if (! $product->is_active) {
                throw ValidationException::withMessages(['product_id' => "{$product->sku} is inactive and can't be reserved."]);
            }

            $warehouseId = (int) $data['warehouse_id'];
            $locationId = isset($data['location_id']) ? (int) $data['location_id'] : null;
            $batchId = isset($data['batch_id']) ? (int) $data['batch_id'] : null;
            $serialId = isset($data['serial_id']) ? (int) $data['serial_id'] : null;
            $qty = (float) ($data['qty'] ?? 1);

            if ($batchId !== null) {
                $belongs = StockBatch::query()->where('id', $batchId)->where('product_id', $productId)->exists();
                if (! $belongs) {
                    throw ValidationException::withMessages(['batch_id' => 'The selected lot does not belong to this product.']);
                }
            }

            // §3M: pinning a specific serial fixes its qty (1) and location — the unit is
            // already sitting somewhere physical, "unassigned" doesn't apply to it.
            $serial = null;
            if ($serialId !== null) {
                $serial = StockSerial::query()->where('id', $serialId)->where('product_id', $productId)->lockForUpdate()->first();
                if (! $serial) {
                    throw ValidationException::withMessages(['serial_id' => 'The selected serial does not belong to this product.']);
                }
                if ($serial->status !== StockSerial::STATUS_IN_STOCK) {
                    throw ValidationException::withMessages(['serial_id' => "Serial \"{$serial->serial_number}\" isn't available to reserve (status: {$serial->status})."]);
                }
                $warehouseId = $serial->warehouse_id;
                $locationId = $serial->location_id;
                $qty = 1.0;
            }

            // Lock every stock_balances row this reservation could draw from — the same
            // row(s) every concurrent reserve() call for this product/warehouse/scope must
            // also lock first, so the availability check below is race-free without needing
            // `FOR UPDATE` on the reservations SUM itself (Postgres forbids that on aggregates).
            StockBalance::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
                ->when($batchId !== null, fn ($q) => $q->where('batch_id', $batchId))
                ->lockForUpdate()
                ->get();

            $onHand = $this->onHandQty($productId, $warehouseId, $locationId, $batchId);
            $reserved = $this->activeReservedQty($productId, $warehouseId, $locationId, $batchId);
            $available = $onHand - $reserved;

            if ($qty > $available + self::EPSILON) {
                throw ValidationException::withMessages([
                    'qty' => "Only {$available} unit(s) of {$product->sku} are available to promise — reduce the quantity or choose another location/lot.",
                ]);
            }

            $reservation = StockReservation::query()->create([
                'product_id' => $productId,
                'batch_id' => $batchId,
                'serial_id' => $serialId,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'qty' => $qty,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'status' => StockReservation::STATUS_ACTIVE,
                'expires_at' => $data['expires_at'] ?? $this->defaultExpiry(),
                'created_by' => auth()->id(),
            ]);

            if ($serial) {
                $serial->update(['status' => StockSerial::STATUS_RESERVED]);
            }

            return $reservation;
        });
    }

    public function release(StockReservation $reservation): void
    {
        $this->assertActive($reservation, 'released');

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => StockReservation::STATUS_RELEASED]);
            $this->freeSerialIfReserved($reservation);
        });
    }

    /**
     * §3P: "ship-confirm is what triggers the actual Goods Issue" — fulfilled is not issued.
     * A pinned serial goes back to in_stock so the normal Goods Issue path (§3E) can pick it
     * up right after; the caller (§3O/§3P, not built) is responsible for doing that promptly.
     */
    public function fulfill(StockReservation $reservation): void
    {
        $this->assertActive($reservation, 'fulfilled');

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => StockReservation::STATUS_FULFILLED]);
            $this->freeSerialIfReserved($reservation);
        });
    }

    /** Auto-release sweep (§3N "expiry, auto-release if not fulfilled by a configurable window") — per-row isolated so one bad row doesn't abort the batch. */
    public function releaseExpired(): int
    {
        $expired = StockReservation::query()
            ->where('status', StockReservation::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $released = 0;
        foreach ($expired as $reservation) {
            try {
                $this->release($reservation);
                $released++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $released;
    }

    /**
     * §3N ATP subtraction. Expiry is checked live here (not trusted to the sweep having run
     * yet — same "compute derived state at query time" posture as BatchController's expiry
     * status) so availability recovers the instant a reservation lapses, not on the next tick.
     * A `location_id = null` (unassigned) reservation is included whenever a specific
     * location is queried — it could be picked from anywhere in the warehouse — but is never
     * double-counted: querying warehouse-wide (`$locationId = null`) sums every reservation
     * in the warehouse exactly once, regardless of how many locations exist under it. Same
     * treatment for a `batch_id = null` (lot-agnostic) reservation against a specific lot —
     * unlike costing (§3L), which never mixes a batch's layers with the non-batch pool, a
     * reservation not pinned to a lot is a floating claim against ANY of that product's lots,
     * so it reduces every lot's own ATP too.
     *
     * `$excludeIds` (§3O): lets a caller resolving bin assignments for a specific *set* of
     * reservations ask "how much is committed by everyone ELSE", so it can maintain its own
     * running per-bin allocation ledger for that set instead of double-counting them.
     *
     * @param  list<int>  $excludeIds
     */
    public function activeReservedQty(int $productId, int $warehouseId, ?int $locationId = null, ?int $batchId = null, array $excludeIds = []): float
    {
        return (float) StockReservation::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', StockReservation::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($locationId !== null, fn ($q) => $q->where(fn ($q2) => $q2->where('location_id', $locationId)->orWhereNull('location_id')))
            ->when($batchId !== null, fn ($q) => $q->where(fn ($q2) => $q2->where('batch_id', $batchId)->orWhereNull('batch_id')))
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->sum('qty');
    }

    /**
     * Deliberately asymmetric (unlike costing's batch scoping, §3L): with no `$batchId`, sums
     * on-hand across every lot — a lot-agnostic reservation validates against the product's
     * total, not an empty "no lot" pool that batch-tracked products never actually have a
     * balance row for (every receipt of a batch-tracked product requires a lot, §3L).
     */
    private function onHandQty(int $productId, int $warehouseId, ?int $locationId, ?int $batchId): float
    {
        return (float) StockBalance::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->when($batchId !== null, fn ($q) => $q->where('batch_id', $batchId))
            ->sum('qty_on_hand');
    }

    private function freeSerialIfReserved(StockReservation $reservation): void
    {
        if ($reservation->serial_id === null) {
            return;
        }

        $serial = $reservation->serial;
        if ($serial && $serial->status === StockSerial::STATUS_RESERVED) {
            $serial->update(['status' => StockSerial::STATUS_IN_STOCK]);
        }
    }

    private function assertActive(StockReservation $reservation, string $action): void
    {
        if ($reservation->status !== StockReservation::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['status' => "Only an active reservation can be {$action}."]);
        }
    }

    private function defaultExpiry(): Carbon
    {
        $hours = (int) ($this->config->get('INVENTORY', 'RESERVATION_EXPIRY_HOURS') ?? 24);

        return now()->addHours($hours);
    }
}
