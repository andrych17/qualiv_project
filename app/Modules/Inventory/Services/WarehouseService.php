<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Validation\ValidationException;

class WarehouseService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Warehouse
    {
        return Warehouse::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        $warehouse->update($this->attributes($data, $warehouse));

        return $warehouse->refresh();
    }

    /** §3C's location-deletion integrity rule, applied one level up: never orphan a location. */
    public function delete(Warehouse $warehouse): void
    {
        $locationCount = $warehouse->locations()->count();
        if ($locationCount > 0) {
            throw ValidationException::withMessages([
                'name' => "This warehouse has {$locationCount} location(s) — delete or move them first.",
            ]);
        }

        $warehouse->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data, ?Warehouse $existing = null): array
    {
        return [
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing?->is_active ?? true),
        ];
    }
}
