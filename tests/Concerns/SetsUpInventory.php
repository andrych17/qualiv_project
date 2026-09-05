<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Modules\Inventory\Models\AdjustmentReason;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;

/** Shared bootstrap for Inventory module tests — plan activation, admin login, and master-data fixtures. */
trait SetsUpInventory
{
    protected function loginAsInventoryAdmin(): Tenant
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        return $tenant;
    }

    protected function makeWarehouse(string $name = 'Main Warehouse'): Warehouse
    {
        return Warehouse::query()->create(['name' => $name, 'is_active' => true]);
    }

    protected function makeLocation(Warehouse $warehouse, string $code = 'A1', string $type = Location::TYPE_BIN): Location
    {
        return Location::query()->create([
            'warehouse_id' => $warehouse->id, 'code' => $code, 'type' => $type, 'is_active' => true,
        ]);
    }

    protected function makeUom(string $code = 'PCS', string $name = 'Piece'): Uom
    {
        return Uom::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
    }

    protected function makeCategory(string $name = 'General'): ProductCategory
    {
        return ProductCategory::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    protected function makeProduct(string $sku = 'SKU-1', array $attrs = []): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $attrs['name'] ?? "Product {$sku}",
            'base_uom_id' => $attrs['base_uom_id'] ?? $this->makeUom()->id,
            'costing_method' => $attrs['costing_method'] ?? Product::COSTING_FIFO,
            'tracking_mode' => $attrs['tracking_mode'] ?? Product::TRACKING_NONE,
            'is_active' => true,
            ...$attrs,
        ]);
    }

    protected function makeAdjustmentReason(string $code = 'other', string $name = 'Other'): AdjustmentReason
    {
        return AdjustmentReason::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
    }
}
