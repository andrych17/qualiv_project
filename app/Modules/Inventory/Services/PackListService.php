<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\PackList;
use App\Modules\Inventory\Models\PackListLine;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3P Packing — builds a `pack_list` (one physical package) from PICKED lines of a single
 * `PickList`. A pick-list line's picked (`confirmed_qty`) amount can be split across several
 * packages (a big pick landing in two cartons), so "how much is left to pack" is derived by
 * summing `pack_list_lines.qty` already recorded against that pick-list line, never stored on
 * the pick-list line itself.
 */
class PackListService
{
    private const EPSILON = 0.0000005;

    /** @param  array<string, mixed>  $data */
    public function create(array $data): PackList
    {
        $pickList = PickList::query()->findOrFail($data['pick_list_id']);

        return DB::transaction(function () use ($pickList, $data) {
            $packList = PackList::query()->create([
                'warehouse_id' => $pickList->warehouse_id,
                'pick_list_id' => $pickList->id,
                'package_type' => $data['package_type'] ?? PackList::TYPE_CARTON,
                'weight' => $data['weight'] ?? null,
                'weight_uom' => $data['weight_uom'] ?? null,
                'length' => $data['length'] ?? null,
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'dimension_uom' => $data['dimension_uom'] ?? null,
                'status' => PackList::STATUS_PACKED,
                'packed_by' => auth()->id(),
                'packed_at' => now(),
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($packList, $data['lines'] ?? []);

            return $packList->load('lines');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(PackList $packList, array $data): PackList
    {
        $this->assertUnshipped($packList);

        return DB::transaction(function () use ($packList, $data) {
            $packList->update([
                'package_type' => $data['package_type'] ?? $packList->package_type,
                'weight' => $data['weight'] ?? null,
                'weight_uom' => $data['weight_uom'] ?? null,
                'length' => $data['length'] ?? null,
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'dimension_uom' => $data['dimension_uom'] ?? null,
            ]);

            $this->syncLines($packList, $data['lines'] ?? []);

            return $packList->refresh()->load('lines');
        });
    }

    public function delete(PackList $packList): void
    {
        $this->assertUnshipped($packList);
        $packList->delete();
    }

    /**
     * Remaining un-packed qty on a picked pick-list line — its `confirmed_qty` minus whatever
     * other packages have already claimed. `$excludePackListId` lets an update() re-validate
     * this pack list's OWN lines without them competing against themselves.
     */
    public function remainingQty(PickListLine $line, ?int $excludePackListId = null): float
    {
        $packed = PackListLine::query()
            ->where('pick_list_line_id', $line->id)
            ->when($excludePackListId, fn ($q, $id) => $q->where('pack_list_id', '!=', $id))
            ->sum('qty');

        return (float) $line->confirmed_qty - (float) $packed;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncLines(PackList $packList, array $lines): void
    {
        $packList->lines()->delete();

        if (empty($lines)) {
            throw ValidationException::withMessages(['lines' => 'Add at least one picked line to this package.']);
        }

        foreach ($lines as $line) {
            if (empty($line['pick_list_line_id']) || empty($line['qty'])) {
                continue;
            }

            $pickListLine = PickListLine::query()
                ->where('id', $line['pick_list_line_id'])
                ->where('pick_list_id', $packList->pick_list_id)
                ->first();

            if (! $pickListLine || $pickListLine->status !== PickListLine::STATUS_PICKED) {
                throw ValidationException::withMessages(['lines' => 'Every line must be a picked line from this pick list.']);
            }

            $qty = (float) $line['qty'];
            $remaining = $this->remainingQty($pickListLine, $packList->id);

            if ($qty <= 0 || $qty > $remaining + self::EPSILON) {
                throw ValidationException::withMessages([
                    'lines' => "Only {$remaining} unit(s) of {$pickListLine->product->sku} remain unpacked on pick line #{$pickListLine->id}.",
                ]);
            }

            PackListLine::query()->create([
                'pack_list_id' => $packList->id,
                'pick_list_line_id' => $pickListLine->id,
                'product_id' => $pickListLine->product_id,
                'batch_id' => $pickListLine->batch_id,
                'serial_id' => $pickListLine->serial_id,
                'qty' => $qty,
            ]);
        }
    }

    private function assertUnshipped(PackList $packList): void
    {
        if ($packList->shipment_id !== null) {
            throw ValidationException::withMessages(['status' => 'This package is already on a shipment and can no longer be changed.']);
        }
    }
}
