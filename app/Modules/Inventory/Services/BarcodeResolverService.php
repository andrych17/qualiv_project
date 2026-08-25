<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\LocationBarcode;
use App\Modules\Inventory\Models\ProductBarcode;

/**
 * §3K — the reusable lookup behind every scan input (Receipt/Issue/Transfer lines scan
 * products; a future Picking/Cycle Count screen scans locations). One exact-match query per
 * call, no fuzzy search — a scanner emits the literal barcode value, never a partial one.
 */
class BarcodeResolverService
{
    /** @return array{product_id: int, sku: string, name: string, uom_id: int, unit_multiplier: float}|null */
    public function resolveProduct(string $code): ?array
    {
        $barcode = ProductBarcode::query()
            ->where('barcode', $code)
            ->with('product:id,sku,name,base_uom_id,is_active')
            ->first();

        if (! $barcode || ! $barcode->product || ! $barcode->product->is_active) {
            return null;
        }

        return [
            'product_id' => $barcode->product->id,
            'sku' => $barcode->product->sku,
            'name' => $barcode->product->name,
            'uom_id' => $barcode->product->base_uom_id,
            'unit_multiplier' => (float) $barcode->unit_multiplier,
        ];
    }

    /** @return array{location_id: int, code: string, warehouse_id: int}|null */
    public function resolveLocation(string $code, ?int $warehouseId = null): ?array
    {
        $barcode = LocationBarcode::query()
            ->where('barcode', $code)
            ->whereHas('location', function ($q) use ($warehouseId) {
                $q->where('is_active', true)->when($warehouseId, fn ($q2) => $q2->where('warehouse_id', $warehouseId));
            })
            ->with('location:id,warehouse_id,code')
            ->first();

        if (! $barcode || ! $barcode->location) {
            return null;
        }

        return [
            'location_id' => $barcode->location->id,
            'code' => $barcode->location->code,
            'warehouse_id' => $barcode->location->warehouse_id,
        ];
    }
}
