<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3O Picking — generates pick lists from active reservations (§3N) and works them via
 * `pickLine()`, the "confirm quantity → line marked picked" step. Picking never touches
 * `stock_ledger`/`stock_balances` itself — it's a workflow layer on top; the actual quantity
 * decrement is still §3E's Goods Issue, triggered later by ship-confirm (§3P, not built).
 *
 * Widens a known §3N gap: picking a serial-pinned reservation flips that serial back to
 * `in_stock` at its original bin (`ReservationService::fulfill()`), even though it's now
 * physically sitting in a tote awaiting packing, not on the shelf. Until §3P exists to move
 * it straight into a Goods Issue, an unrelated Issue could take that same serial. Accepted for
 * MVP — the alternative (a `picked`/`in_transit` serial status with no consumer yet) is
 * complexity with no caller to justify it.
 */
class PickListService
{
    private const EPSILON = 0.0000005;

    public function __construct(protected ReservationService $reservations) {}

    /**
     * One pick list per warehouse ("grouped by warehouse", §3O) — reservations spanning
     * several warehouses in one call produce several lists, each generated in its OWN
     * transaction. That matters: a location-resolution failure in one warehouse (e.g. no
     * single bin covers an unassigned reservation) must not roll back a different, unrelated
     * warehouse's pick list. Failures are collected per warehouse group instead of aborting
     * the whole call; only a call where EVERY group failed throws.
     *
     * @param  list<int>  $reservationIds
     * @return array{lists: Collection<int, PickList>, errors: list<string>}
     */
    public function generate(array $reservationIds, ?int $assignedTo = null): array
    {
        $reservations = StockReservation::query()
            ->whereIn('id', $reservationIds)
            ->where('status', StockReservation::STATUS_ACTIVE)
            ->get();

        if ($reservations->isEmpty()) {
            throw ValidationException::withMessages(['reservations' => 'Select at least one active reservation.']);
        }

        $pickLists = collect();
        $errors = [];

        foreach ($reservations->groupBy('warehouse_id') as $warehouseId => $group) {
            try {
                $pickLists->push($this->generateForWarehouse((int) $warehouseId, $group->pluck('id')->all(), $assignedTo));
            } catch (ValidationException $e) {
                $errors[] = collect($e->errors())->flatten()->implode(' ');
            }
        }

        if ($pickLists->isEmpty()) {
            throw ValidationException::withMessages(['reservations' => $errors[0] ?? 'Could not generate any pick list.']);
        }

        return ['lists' => $pickLists, 'errors' => $errors];
    }

    /**
     * @param  list<int>  $reservationIds  ids belonging to a single warehouse group.
     */
    private function generateForWarehouse(int $warehouseId, array $reservationIds, ?int $assignedTo): PickList
    {
        return DB::transaction(function () use ($warehouseId, $reservationIds, $assignedTo) {
            $reservations = StockReservation::query()
                ->whereIn('id', $reservationIds)
                ->where('status', StockReservation::STATUS_ACTIVE)
                ->with('product')
                ->lockForUpdate()
                ->get();

            if ($reservations->isEmpty()) {
                throw ValidationException::withMessages(['reservations' => "Warehouse #{$warehouseId}: selected reservations are no longer active."]);
            }

            $pickList = PickList::query()->create([
                'warehouse_id' => $warehouseId,
                'status' => PickList::STATUS_PENDING,
                'assigned_to' => $assignedTo,
                'created_by' => auth()->id(),
            ]);

            // Running per-bin allocation ledger for THIS call only — see resolvePickLocation()'s
            // docblock for why this can't just be derived from activeReservedQty() alone.
            // Pre-seeded with the group's already-assigned reservations, since those hold a real
            // claim on their own bin that unassigned siblings must not be allowed to eat into.
            $groupIds = $reservations->pluck('id')->all();
            $claimed = [];
            foreach ($reservations as $r) {
                if ($r->location_id !== null) {
                    $claimed[$r->location_id] = ($claimed[$r->location_id] ?? 0.0) + (float) $r->qty;
                }
            }

            foreach ($reservations as $reservation) {
                if ($reservation->location_id !== null) {
                    $locationId = $reservation->location_id;
                } else {
                    $locationId = $this->resolvePickLocation($reservation, $groupIds, $claimed);
                    $claimed[$locationId] = ($claimed[$locationId] ?? 0.0) + (float) $reservation->qty;
                }

                PickListLine::query()->create([
                    'pick_list_id' => $pickList->id,
                    'reservation_id' => $reservation->id,
                    'product_id' => $reservation->product_id,
                    'batch_id' => $reservation->batch_id,
                    'serial_id' => $reservation->serial_id,
                    'location_id' => $locationId,
                    'qty' => $reservation->qty,
                    'status' => PickListLine::STATUS_PENDING,
                ]);
            }

            return $pickList->load('lines');
        });
    }

    public function assign(PickList $pickList, ?int $userId): void
    {
        $pickList->update(['assigned_to' => $userId]);
    }

    /**
     * §3O "scan location barcode → scan product barcode → confirm quantity → line marked
     * picked" — the two scans are verified client-side against this line's own product/
     * location before this is ever called; this is the "confirm quantity" step. Fulfils the
     * underlying reservation immediately — physically pulling the stock IS the moment the
     * promise is honored (see `ReservationService::fulfill()`'s own docblock).
     */
    public function pickLine(PickListLine $line, float $confirmedQty): void
    {
        if ($line->status !== PickListLine::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'This line has already been picked.']);
        }
        if ($confirmedQty <= 0 || $confirmedQty > (float) $line->qty + self::EPSILON) {
            throw ValidationException::withMessages(['confirmed_qty' => "Confirmed quantity must be more than zero and no more than the expected {$line->qty}."]);
        }

        DB::transaction(function () use ($line, $confirmedQty) {
            $line->update([
                'status' => PickListLine::STATUS_PICKED,
                'confirmed_qty' => $confirmedQty,
                'picked_at' => now(),
                'picked_by' => auth()->id(),
            ]);

            try {
                $this->reservations->fulfill($line->reservation);
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    'status' => 'The reservation behind this line was released or already fulfilled elsewhere — this line can no longer be picked. Regenerate the pick list.',
                ]);
            }

            $pickList = $line->pickList()->lockForUpdate()->first();
            $hasPending = $pickList->lines()->where('status', PickListLine::STATUS_PENDING)->exists();

            if (! $hasPending) {
                $pickList->update(['status' => PickList::STATUS_READY_FOR_PACKING, 'completed_at' => now()]);
            } elseif ($pickList->status === PickList::STATUS_PENDING) {
                $pickList->update(['status' => PickList::STATUS_IN_PROGRESS]);
            }
        });
    }

    /** Only a pick list with no picked lines yet can be scrapped — one with progress is corrected line by line, not deleted out from under a picker. */
    public function delete(PickList $pickList): void
    {
        if ($pickList->lines()->where('status', PickListLine::STATUS_PICKED)->exists()) {
            throw ValidationException::withMessages(['status' => "This pick list already has picked lines and can't be deleted."]);
        }

        $pickList->delete();
    }

    /**
     * §3O: an unassigned reservation ("pending pick") resolves to a concrete location here —
     * this is exactly the moment "which bin" gets decided. MVP simplification, flagged
     * deliberately: a pick line always maps to exactly one location, never split across bins
     * — a real WMS capability deferred past Operational tier. This also means resolution is
     * greedy, not a joint bin-packing solve: a batch of several unassigned reservations
     * competing for the same bins may reject more of them than a smarter solver would.
     *
     * Checking on-hand minus `activeReservedQty()` alone is NOT enough once more than one
     * unassigned reservation is being resolved in the same `generate()` call: that method
     * counts every unassigned reservation against every bin symmetrically (by design, per its
     * own docblock — a floating claim reduces ATP everywhere), so two siblings being resolved
     * back to back would see the *identical* number at the same candidate bin and both pass
     * independently, landing on the same bin. The caller threads through `$excludeIds` (every
     * reservation in this warehouse group) so this method sees only externally-committed
     * demand, plus `$claimed` — its own running ledger of what THIS call has already put in
     * each bin — so each successive resolution sees the previous ones' allocations actually
     * taken out of capacity.
     *
     * `lockForUpdate()` on the balance rows matters here too: this only runs inside
     * `generateForWarehouse()`'s per-warehouse transaction, so two concurrent `generate()`
     * calls for the *same* warehouse would otherwise both read stale capacity before either
     * committed its reservation-row lock. Locking the candidate balances serializes that.
     *
     * @param  list<int>  $excludeIds  every reservation id in this warehouse group — excluded
     *                                 from `activeReservedQty()` so it reports only external demand.
     * @param  array<int, float>  $claimed  location_id => qty already allocated by this call so far.
     */
    private function resolvePickLocation(StockReservation $reservation, array $excludeIds, array $claimed): int
    {
        $candidates = StockBalance::query()
            ->where('product_id', $reservation->product_id)
            ->where('warehouse_id', $reservation->warehouse_id)
            ->when($reservation->batch_id !== null, fn ($q) => $q->where('batch_id', $reservation->batch_id))
            ->where('qty_on_hand', '>', 0)
            ->orderByDesc('qty_on_hand')
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $balance) {
            $externalReserved = $this->reservations->activeReservedQty(
                $reservation->product_id, $reservation->warehouse_id, $balance->location_id, $reservation->batch_id, $excludeIds,
            );
            $alreadyClaimed = $claimed[$balance->location_id] ?? 0.0;
            $available = (float) $balance->qty_on_hand - $externalReserved - $alreadyClaimed;

            if ($available >= (float) $reservation->qty - self::EPSILON) {
                return $balance->location_id;
            }
        }

        throw ValidationException::withMessages([
            'reservations' => "No single location has enough uncommitted on-hand stock of {$reservation->product->sku} to pick this reservation ({$reservation->qty} units) — split it into smaller reservations first.",
        ]);
    }
}
