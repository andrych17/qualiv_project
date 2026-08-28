<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\PackList;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3P Shipping — links one or more packed `PackList`s (each already validated unshipped by
 * `pack_lists.shipment_id` being null) and, on `shipConfirm()`, turns their contents into a
 * real §3E Goods Issue: "Ship-confirm is what triggers the actual Goods Issue... the real
 * inventory-decrementing event, not the earlier pick/pack steps." `create()`/`post()` on
 * GoodsIssueService are called as two separate top-level actions (not nested inside this
 * service's own transaction) — same non-atomic posture Transfers already accept for their own
 * post()+complete() pair, so a failure between "issue posted" and "shipment marked shipped" is
 * a recoverable, manually-fixable edge case rather than a distributed-transaction problem this
 * codebase otherwise takes on.
 *
 * Known MVP gap: if a batch on a packed line expires in the (usually short) window between
 * picking and ship-confirm, `GoodsIssueService::post()` will block on it with no override path
 * here — the override UI only exists on Goods Issue's own line editor (§3L). Accepted for v1;
 * revisit if this proves a real operational friction once Legal-adjacent verticals ship physical goods.
 */
class ShipmentService
{
    public function __construct(protected GoodsIssueService $goodsIssues) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Shipment
    {
        $packLists = PackList::query()
            ->whereIn('id', $data['pack_list_ids'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->get();

        $this->assertAssignable($packLists, $data['pack_list_ids']);

        return DB::transaction(function () use ($data, $packLists) {
            $shipment = Shipment::query()->create([
                'warehouse_id' => $data['warehouse_id'],
                'carrier' => $data['carrier'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'ship_date' => $data['ship_date'] ?? null,
                'status' => Shipment::STATUS_PENDING,
                'created_by' => auth()->id(),
            ]);

            PackList::query()->whereIn('id', $packLists->pluck('id'))->update(['shipment_id' => $shipment->id]);

            return $shipment->load('packLists');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Shipment $shipment, array $data): Shipment
    {
        $this->assertPending($shipment);

        $packLists = PackList::query()
            ->whereIn('id', $data['pack_list_ids'])
            ->where('warehouse_id', $shipment->warehouse_id)
            ->get();

        $this->assertAssignable($packLists, $data['pack_list_ids'], excludeShipmentId: $shipment->id);

        return DB::transaction(function () use ($shipment, $data, $packLists) {
            $shipment->update([
                'carrier' => $data['carrier'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'ship_date' => $data['ship_date'] ?? null,
            ]);

            // Release every package currently on this shipment, then reassign exactly the
            // requested set — simplest correct way to handle both additions and removals.
            PackList::query()->where('shipment_id', $shipment->id)->update(['shipment_id' => null]);
            PackList::query()->whereIn('id', $packLists->pluck('id'))->update(['shipment_id' => $shipment->id]);

            return $shipment->refresh()->load('packLists');
        });
    }

    public function delete(Shipment $shipment): void
    {
        $this->assertPending($shipment);

        DB::transaction(function () use ($shipment) {
            PackList::query()->where('shipment_id', $shipment->id)->update(['shipment_id' => null]);
            $shipment->delete();
        });
    }

    public function shipConfirm(Shipment $shipment): Shipment
    {
        $this->assertPending($shipment);

        $packLists = $shipment->packLists()
            ->with(['lines.product', 'lines.serial', 'lines.pickListLine'])
            ->get();

        $lines = $packLists->flatMap(fn (PackList $p) => $p->lines);
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['pack_lists' => 'Add at least one package before ship-confirm.']);
        }

        $issueLines = $lines->map(function ($line) {
            /** @var Product $product */
            $product = $line->product;

            return [
                'product_id' => $line->product_id,
                'qty' => (float) $line->qty,
                'uom_id' => $product->base_uom_id,
                'source_location_id' => $line->pickListLine->location_id,
                'batch_id' => $line->batch_id,
                'serial_numbers' => $line->serial_id ? [$line->serial->serial_number] : null,
            ];
        })->values()->all();

        $issue = $this->goodsIssues->create([
            'warehouse_id' => $shipment->warehouse_id,
            'issue_date' => now()->toDateString(),
            'subject_type' => 'inventory.shipments',
            'subject_id' => (string) $shipment->id,
            'lines' => $issueLines,
        ]);
        $issue = $this->goodsIssues->post($issue);

        DB::transaction(function () use ($shipment, $issue, $packLists) {
            $shipment->update([
                'status' => Shipment::STATUS_SHIPPED,
                'goods_issue_id' => $issue->id,
                'shipped_by' => auth()->id(),
                'shipped_at' => now(),
            ]);

            PackList::query()->whereIn('id', $packLists->pluck('id'))->update(['status' => PackList::STATUS_SHIPPED]);
        });

        return $shipment->refresh()->load('packLists', 'goodsIssue');
    }

    /** §3P: "delivered is manual/webhook-updated, not tracked live in v1" — manual button for MVP. */
    public function markDelivered(Shipment $shipment): Shipment
    {
        if ($shipment->status !== Shipment::STATUS_SHIPPED) {
            throw ValidationException::withMessages(['status' => 'Only a shipped shipment can be marked delivered.']);
        }

        $shipment->update(['status' => Shipment::STATUS_DELIVERED, 'delivered_at' => now()]);

        return $shipment;
    }

    /** @param  Collection<int, PackList>  $packLists  @param  list<int>  $requestedIds */
    private function assertAssignable($packLists, array $requestedIds, ?int $excludeShipmentId = null): void
    {
        if ($packLists->count() !== count(array_unique($requestedIds))) {
            throw ValidationException::withMessages(['pack_list_ids' => 'One or more selected packages do not belong to this warehouse.']);
        }

        $alreadyAssigned = $packLists->first(fn (PackList $p) => $p->shipment_id !== null && $p->shipment_id !== $excludeShipmentId);
        if ($alreadyAssigned) {
            throw ValidationException::withMessages(['pack_list_ids' => "Package #{$alreadyAssigned->id} is already on another shipment."]);
        }
    }

    private function assertPending(Shipment $shipment): void
    {
        if ($shipment->status !== Shipment::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'This shipment has already shipped and can no longer be changed.']);
        }
    }
}
