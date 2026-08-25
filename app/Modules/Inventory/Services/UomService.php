<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\UomConversion;
use Illuminate\Validation\ValidationException;

class UomService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Uom
    {
        return Uom::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(Uom $uom, array $data): Uom
    {
        $uom->update($this->attributes($data));

        return $uom->refresh();
    }

    public function delete(Uom $uom): void
    {
        $inUse = Product::query()->where('base_uom_id', $uom->id)->exists()
            || UomConversion::query()->where('uom_id', $uom->id)->exists();

        if ($inUse) {
            throw ValidationException::withMessages(['code' => 'This UoM is in use by a product — it can only be deactivated.']);
        }

        $uom->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }
}
