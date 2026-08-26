<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\LocationBarcode;
use App\Modules\Inventory\Models\StockBalance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocationService
{
    /** @param  array<string, mixed>  $data */
    public function create(int $warehouseId, array $data): Location
    {
        return DB::transaction(function () use ($warehouseId, $data) {
            $location = Location::query()->create($this->attributes($warehouseId, $data));
            $this->syncBarcodes($location, $data['barcodes'] ?? []);

            return $location;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Location $location, array $data): Location
    {
        return DB::transaction(function () use ($location, $data) {
            $location->update($this->attributes($location->warehouse_id, $data));
            $this->syncBarcodes($location, $data['barcodes'] ?? []);

            return $location->refresh();
        });
    }

    public function delete(Location $location): void
    {
        $childCount = $location->children()->count();
        if ($childCount > 0) {
            throw ValidationException::withMessages([
                'code' => "This location has {$childCount} sub-location(s) — delete or move them first.",
            ]);
        }

        $hasStock = StockBalance::query()
            ->where('location_id', $location->id)
            ->where('qty_on_hand', '>', 0)
            ->exists();

        if ($hasStock) {
            throw ValidationException::withMessages([
                'code' => "Cannot delete location {$location->code} because it holds on-hand stock.",
            ]);
        }

        $location->delete();
    }

    /**
     * Depth-first flat list with `depth` set on each row, for the warehouse's location tree
     * (embedded in the Warehouse Edit page) — same shape as DMS's FolderController::indent().
     *
     * @return Collection<int, Location>
     */
    public function indented(int $warehouseId): Collection
    {
        $locations = Location::query()->where('warehouse_id', $warehouseId)->withCount('children')->orderBy('code')->get();
        $byParent = $locations->groupBy('parent_location_id');
        $ordered = collect();

        $walk = function (?int $parentId, int $depth) use (&$walk, &$ordered, $byParent) {
            foreach ($byParent->get($parentId) ?? [] as $location) {
                $location->depth = $depth;
                $ordered->push($location);
                $walk($location->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $ordered;
    }

    /**
     * Flat, depth-indented parent options for a warehouse, excluding $excludeLocationId's own
     * subtree (prevents parent cycles) — same shape as DMS's FolderController::parentOptions().
     *
     * @return list<array{value: int, label: string}>
     */
    public function parentOptions(int $warehouseId, ?int $excludeLocationId = null): array
    {
        $locations = Location::query()->where('warehouse_id', $warehouseId)->orderBy('code')->get(['id', 'code', 'parent_location_id']);
        $excludeIds = $excludeLocationId ? $this->subtreeIds($locations, $excludeLocationId) : [];

        $byParent = $locations->groupBy('parent_location_id');
        $flatten = function (?int $parentId, int $depth) use (&$flatten, $byParent, $excludeIds) {
            return ($byParent->get($parentId) ?? collect())
                ->reject(fn (Location $l) => in_array($l->id, $excludeIds, true))
                ->flatMap(fn (Location $l) => collect([
                    ['value' => $l->id, 'label' => str_repeat('— ', $depth).$l->code],
                ])->concat($flatten($l->id, $depth + 1)));
        };

        return $flatten(null, 0)->values()->all();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(int $warehouseId, array $data): array
    {
        return [
            'warehouse_id' => $warehouseId,
            'parent_location_id' => empty($data['parent_location_id']) ? null : $data['parent_location_id'],
            'code' => $data['code'],
            'type' => $data['type'] ?? Location::TYPE_BIN,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function syncBarcodes(Location $location, array $rows): void
    {
        $values = array_values(array_filter(array_map(fn ($row) => trim((string) ($row['barcode'] ?? '')), $rows), fn ($v) => $v !== ''));

        if (count($values) !== count(array_unique($values))) {
            throw ValidationException::withMessages(['barcodes' => 'Duplicate barcode value within this location.']);
        }

        if ($values !== [] && LocationBarcode::query()->whereIn('barcode', $values)->where('location_id', '!=', $location->id)->exists()) {
            throw ValidationException::withMessages(['barcodes' => 'One or more barcodes are already used by another location.']);
        }

        LocationBarcode::query()->where('location_id', $location->id)->delete();

        foreach ($values as $barcode) {
            LocationBarcode::query()->create(['location_id' => $location->id, 'barcode' => $barcode]);
        }
    }

    /** @return list<int> $rootId and every descendant id, via a plain BFS over an already-loaded collection. */
    private function subtreeIds(Collection $locations, int $rootId): array
    {
        $ids = [$rootId];
        $queue = [$rootId];

        while ($queue) {
            $current = array_pop($queue);
            foreach ($locations->where('parent_location_id', $current) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }
}
