<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Adjustment;
use App\Modules\Inventory\Models\AdjustmentReason;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\LocationBarcode;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3C/§4 — Warehouse, Location (tree), UoM, Product Category (tree), Adjustment Reason: master-data CRUD and their delete-integrity guards. */
class MasterDataTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    public function test_admin_can_crud_a_warehouse_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $this->get('/inventory/warehouses')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Warehouses/Index'));
        $this->get('/inventory/warehouses/create')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Warehouses/Create'));

        $this->post('/inventory/warehouses', ['name' => 'Main WH', 'address' => '1 Dock Rd'])
            ->assertRedirect(route('inventory.warehouses.index'));

        $warehouseId = null;
        $tenant->run(function () use (&$warehouseId) {
            $warehouseId = Warehouse::query()->where('name', 'Main WH')->value('id');
        });

        $this->get("/inventory/warehouses/{$warehouseId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Warehouses/Edit')->where('warehouse.name', 'Main WH'));

        $this->put("/inventory/warehouses/{$warehouseId}", ['name' => 'Main WH (renamed)', 'is_active' => true])
            ->assertRedirect(route('inventory.warehouses.index'));

        $tenant->run(function () use ($warehouseId) {
            $this->assertSame('Main WH (renamed)', Warehouse::query()->find($warehouseId)->name);
        });

        $this->delete("/inventory/warehouses/{$warehouseId}")->assertRedirect(route('inventory.warehouses.index'));
        $tenant->run(function () use ($warehouseId) {
            $this->assertNull(Warehouse::query()->find($warehouseId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makeWarehouse('Bulk A')->id;
            $ids[] = $this->makeWarehouse('Bulk B')->id;
        });
        $this->delete('/inventory/warehouses/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, Warehouse::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_warehouse_delete_is_blocked_while_it_has_locations(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $tenant->run(function () use (&$warehouseId) {
            $warehouse = $this->makeWarehouse();
            $this->makeLocation($warehouse);
            $warehouseId = $warehouse->id;
        });

        $this->delete("/inventory/warehouses/{$warehouseId}")->assertSessionHasErrors(['name']);
    }

    public function test_admin_can_crud_a_location_tree_under_a_warehouse_with_barcodes(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $tenant->run(function () use (&$warehouseId) {
            $warehouseId = $this->makeWarehouse()->id;
        });

        $this->get("/inventory/warehouses/{$warehouseId}/locations/create")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Locations/Create'));

        $this->post("/inventory/warehouses/{$warehouseId}/locations", [
            'code' => 'Z1', 'type' => 'zone', 'barcodes' => [['barcode' => 'LOC-Z1']],
        ])->assertRedirect(route('inventory.warehouses.edit', $warehouseId));

        $parentId = null;
        $tenant->run(function () use (&$parentId, $warehouseId) {
            $parentId = Location::query()->where('warehouse_id', $warehouseId)->where('code', 'Z1')->value('id');
            $this->assertSame(1, LocationBarcode::query()->where('location_id', $parentId)->count());
        });

        $this->post("/inventory/warehouses/{$warehouseId}/locations", [
            'code' => 'A1', 'type' => 'bin', 'parent_location_id' => $parentId,
        ])->assertRedirect();

        $childId = null;
        $tenant->run(function () use (&$childId, $warehouseId) {
            $childId = Location::query()->where('warehouse_id', $warehouseId)->where('code', 'A1')->value('id');
        });

        $this->get("/inventory/warehouses/{$warehouseId}/locations/{$childId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Locations/Edit')->where('location.code', 'A1'));

        // Editing the PARENT (which has a real child) exercises parentOptions()'s own-subtree
        // exclusion walk — editing the childless child above never reaches that loop body.
        $this->get("/inventory/warehouses/{$warehouseId}/locations/{$parentId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Locations/Edit')->where('location.code', 'Z1'));

        // The warehouse edit page renders the location tree via LocationService::indented() —
        // only exercises its walk() body when real parent/child rows exist, as they do now.
        $this->get("/inventory/warehouses/{$warehouseId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->has('locations', 2));

        // Duplicate barcode within the same location, and a barcode already used by another location.
        $this->post("/inventory/warehouses/{$warehouseId}/locations", [
            'code' => 'DUP-BC-LOC', 'type' => 'bin', 'barcodes' => [['barcode' => 'SAME'], ['barcode' => 'SAME']],
        ])->assertSessionHasErrors(['barcodes']);
        $this->post("/inventory/warehouses/{$warehouseId}/locations", [
            'code' => 'CONFLICT-BC-LOC', 'type' => 'bin', 'barcodes' => [['barcode' => 'LOC-Z1']],
        ])->assertSessionHasErrors(['barcodes']);

        // Moving the parent under its own child would create a cycle.
        $this->put("/inventory/warehouses/{$warehouseId}/locations/{$parentId}", [
            'code' => 'Z1', 'type' => 'zone', 'parent_location_id' => $childId,
        ])->assertSessionHasErrors(['parent_location_id']);

        $this->put("/inventory/warehouses/{$warehouseId}/locations/{$childId}", [
            'code' => 'A1 (renamed)', 'type' => 'bin', 'is_active' => true, 'parent_location_id' => $parentId,
        ])->assertRedirect(route('inventory.warehouses.edit', $warehouseId));

        $tenant->run(function () use ($childId) {
            $this->assertSame('A1 (renamed)', Location::query()->find($childId)->code);
        });

        // Parent has a child -> blocked.
        $this->delete("/inventory/warehouses/{$warehouseId}/locations/{$parentId}")->assertSessionHasErrors(['code']);

        $this->delete("/inventory/warehouses/{$warehouseId}/locations/{$childId}")->assertRedirect(route('inventory.warehouses.edit', $warehouseId));
        $tenant->run(function () use ($childId) {
            $this->assertNull(Location::query()->find($childId));
        });
    }

    public function test_location_delete_is_blocked_while_it_holds_on_hand_stock(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct();
            StockBalance::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 5]);
            $warehouseId = $warehouse->id;
            $locationId = $location->id;
        });

        $this->delete("/inventory/warehouses/{$warehouseId}/locations/{$locationId}")->assertSessionHasErrors(['code']);
    }

    public function test_store_and_update_location_validation_rejects_duplicate_code_and_invalid_parent(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $otherLocationId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId, &$otherLocationId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $locationId = $this->makeLocation($warehouse, 'DUPE')->id;
            $otherLocationId = $this->makeLocation($warehouse, 'OTHER-LOC')->id;
        });

        $this->post("/inventory/warehouses/{$warehouseId}/locations", ['code' => 'DUPE'])
            ->assertSessionHasErrors(['code']);

        $this->post("/inventory/warehouses/{$warehouseId}/locations", ['code' => 'NEW', 'parent_location_id' => 999999])
            ->assertSessionHasErrors(['parent_location_id']);

        $this->put("/inventory/warehouses/{$warehouseId}/locations/{$locationId}", ['code' => 'DUPE', 'parent_location_id' => $locationId])
            ->assertSessionHasErrors(['parent_location_id']);

        // Duplicate code taken by a DIFFERENT location in the same warehouse (not the one being edited).
        $this->put("/inventory/warehouses/{$warehouseId}/locations/{$otherLocationId}", ['code' => 'DUPE'])
            ->assertSessionHasErrors(['code']);

        // A parent_location_id that doesn't exist at all (rather than a cycle) on update.
        $this->put("/inventory/warehouses/{$warehouseId}/locations/{$otherLocationId}", ['code' => 'OTHER-LOC', 'parent_location_id' => 999999])
            ->assertSessionHasErrors(['parent_location_id']);

        // Omitting parent_location_id entirely on a root location (no parent to begin with) is
        // the early-return "nothing to validate" path, not an implicit clear-to-null.
        $this->put("/inventory/warehouses/{$warehouseId}/locations/{$otherLocationId}", ['code' => 'OTHER-LOC (renamed)'])
            ->assertRedirect(route('inventory.warehouses.edit', $warehouseId));
    }

    public function test_admin_can_crud_a_uom_and_delete_is_blocked_when_in_use(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $this->get('/inventory/uoms')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Uoms/Index'));
        $this->get('/inventory/uoms/create')->assertOk();

        $this->post('/inventory/uoms', ['code' => 'box', 'name' => 'Box'])->assertRedirect(route('inventory.uoms.index'));

        $uomId = null;
        $tenant->run(function () use (&$uomId) {
            $uom = Uom::query()->where('code', 'BOX')->first();
            $this->assertNotNull($uom);
            $uomId = $uom->id;
        });

        $this->get("/inventory/uoms/{$uomId}/edit")->assertOk();
        $this->put("/inventory/uoms/{$uomId}", ['code' => 'BOX', 'name' => 'Box (renamed)'])->assertRedirect(route('inventory.uoms.index'));
        $tenant->run(function () use ($uomId) {
            $this->assertSame('Box (renamed)', Uom::query()->find($uomId)->name);
        });

        $this->post('/inventory/uoms', ['code' => 'BOX', 'name' => 'Dup'])->assertSessionHasErrors(['code']);
        $this->put("/inventory/uoms/{$uomId}", ['code' => 'BOX', 'name' => 'Fine'])->assertRedirect();

        // The update-time duplicate check is its own code path, distinct from store's.
        $secondUomId = null;
        $tenant->run(function () use (&$secondUomId) {
            $secondUomId = $this->makeUom('CASE', 'Case')->id;
        });
        $this->put("/inventory/uoms/{$secondUomId}", ['code' => 'BOX', 'name' => 'Case'])->assertSessionHasErrors(['code']);

        $productUomId = null;
        $tenant->run(function () use (&$productUomId) {
            $productUomId = $this->makeUom('EA', 'Each')->id;
            $this->makeProduct('SKU-UOM', ['base_uom_id' => $productUomId]);
        });
        $this->delete("/inventory/uoms/{$productUomId}")->assertSessionHasErrors(['code']);

        $this->delete("/inventory/uoms/{$uomId}")->assertRedirect(route('inventory.uoms.index'));
        $tenant->run(function () use ($uomId) {
            $this->assertNull(Uom::query()->find($uomId));
        });

        $this->delete('/inventory/uoms/bulk-destroy', ['ids' => []])->assertSessionHasErrors(['ids']);
    }

    public function test_uom_index_filters_by_search_and_status(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $this->makeUom('KG', 'Kilogram');
            $this->makeUom('LB', 'Pound')->update(['is_active' => false]);
        });

        $this->get('/inventory/uoms?search=Kilogram')->assertOk()
            ->assertInertia(fn ($page) => $page->has('uoms.data', 1)->where('uoms.data.0.code', 'KG'));

        $this->get('/inventory/uoms?status=inactive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('uoms.data', 1)->where('uoms.data.0.code', 'LB'));

        $this->get('/inventory/uoms?sort=code&direction=desc&per_page=5')->assertOk()
            ->assertInertia(fn ($page) => $page->where('uoms.data.0.code', 'LB'));
    }

    public function test_admin_can_crud_a_product_category_tree_and_delete_guards(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $this->get('/inventory/categories')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Categories/Index'));
        $this->get('/inventory/categories/create')->assertOk();

        $this->post('/inventory/categories', ['name' => 'Electronics'])->assertRedirect(route('inventory.categories.index'));

        $parentId = null;
        $tenant->run(function () use (&$parentId) {
            $parentId = ProductCategory::query()->where('name', 'Electronics')->value('id');
        });

        $this->post('/inventory/categories', ['name' => 'Cables', 'parent_category_id' => $parentId])->assertRedirect();

        $childId = null;
        $tenant->run(function () use (&$childId) {
            $childId = ProductCategory::query()->where('name', 'Cables')->value('id');
        });

        $this->get("/inventory/categories/{$childId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Categories/Edit')->where('category.name', 'Cables'));

        $this->put("/inventory/categories/{$parentId}", ['name' => 'Electronics', 'parent_category_id' => $parentId])
            ->assertSessionHasErrors(['parent_category_id']);

        $this->put("/inventory/categories/{$childId}", ['name' => 'Cables & Adapters', 'is_active' => true, 'parent_category_id' => $parentId])->assertRedirect();
        $tenant->run(function () use ($childId) {
            $this->assertSame('Cables & Adapters', ProductCategory::query()->find($childId)->name);
        });

        // Parent has a child -> blocked.
        $this->delete("/inventory/categories/{$parentId}")->assertSessionHasErrors(['name']);

        $productCategoryId = null;
        $tenant->run(function () use (&$productCategoryId) {
            $productCategoryId = $this->makeCategory('Assigned')->id;
            $this->makeProduct('SKU-CAT', ['category_id' => $productCategoryId]);
        });
        $this->delete("/inventory/categories/{$productCategoryId}")->assertSessionHasErrors(['name']);

        $this->delete("/inventory/categories/{$childId}")->assertRedirect(route('inventory.categories.index'));
        $tenant->run(function () use ($childId) {
            $this->assertNull(ProductCategory::query()->find($childId));
        });

        $this->delete('/inventory/categories/bulk-destroy', ['ids' => []])->assertSessionHasErrors(['ids']);
    }

    public function test_store_and_update_category_validation_rejects_invalid_parent(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $this->post('/inventory/categories', ['name' => 'Bad', 'parent_category_id' => 999999])
            ->assertSessionHasErrors(['parent_category_id']);

        $categoryId = null;
        $tenant->run(function () use (&$categoryId) {
            $categoryId = $this->makeCategory('Update Target')->id;
        });

        // A nonexistent parent on UPDATE is a distinct code path from the self-parent guard.
        $this->put("/inventory/categories/{$categoryId}", ['name' => 'Update Target', 'parent_category_id' => 999999])
            ->assertSessionHasErrors(['parent_category_id']);
    }

    public function test_admin_can_crud_an_adjustment_reason_and_delete_is_blocked_when_in_use(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $this->get('/inventory/adjustment-reasons')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/AdjustmentReasons/Index'));
        $this->get('/inventory/adjustment-reasons/create')->assertOk();

        $this->post('/inventory/adjustment-reasons', ['code' => 'cycle_variance', 'name' => 'Cycle Variance'])
            ->assertRedirect(route('inventory.adjustmentReasons.index'));

        $reasonId = null;
        $tenant->run(function () use (&$reasonId) {
            $reasonId = AdjustmentReason::query()->where('code', 'cycle_variance')->value('id');
        });

        $this->get("/inventory/adjustment-reasons/{$reasonId}/edit")->assertOk();
        $this->put("/inventory/adjustment-reasons/{$reasonId}", ['code' => 'cycle_variance', 'name' => 'Cycle Variance (v2)'])
            ->assertRedirect(route('inventory.adjustmentReasons.index'));
        $tenant->run(function () use ($reasonId) {
            $this->assertSame('Cycle Variance (v2)', AdjustmentReason::query()->find($reasonId)->name);
        });

        $this->post('/inventory/adjustment-reasons', ['code' => 'cycle_variance', 'name' => 'Dup'])->assertSessionHasErrors(['code']);
        $this->put("/inventory/adjustment-reasons/{$reasonId}", ['code' => 'other', 'name' => 'Collides'])->assertSessionHasErrors(['code']);

        $inUseId = null;
        $tenant->run(function () use (&$inUseId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $reason = $this->makeAdjustmentReason('in_use', 'In use');
            Adjustment::query()->create([
                'warehouse_id' => $warehouse->id,
                'location_id' => $location->id,
                'adjustment_date' => now()->toDateString(),
                'reason_id' => $reason->id,
                'status' => 'draft',
            ]);
            $inUseId = $reason->id;
        });
        $this->delete("/inventory/adjustment-reasons/{$inUseId}")->assertSessionHasErrors(['code']);

        $this->delete("/inventory/adjustment-reasons/{$reasonId}")->assertRedirect(route('inventory.adjustmentReasons.index'));
        $tenant->run(function () use ($reasonId) {
            $this->assertNull(AdjustmentReason::query()->find($reasonId));
        });

        $this->delete('/inventory/adjustment-reasons/bulk-destroy', ['ids' => []])->assertSessionHasErrors(['ids']);
    }

    public function test_warehouse_category_and_adjustment_reason_indexes_filter_by_search_and_status(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $this->makeWarehouse('Findable WH');
            $this->makeWarehouse('Other WH')->update(['is_active' => false]);

            ProductCategory::query()->create(['name' => 'Findable Cat', 'is_active' => true]);
            ProductCategory::query()->create(['name' => 'Other Cat', 'is_active' => false]);

            AdjustmentReason::query()->create(['code' => 'findable_reason', 'name' => 'Findable Reason', 'is_active' => true]);
            AdjustmentReason::query()->create(['code' => 'other_reason', 'name' => 'Other Reason', 'is_active' => false]);
        });

        $this->get('/inventory/warehouses?search=Findable')->assertOk()
            ->assertInertia(fn ($page) => $page->has('warehouses.data', 1)->where('warehouses.data.0.name', 'Findable WH'));
        $this->get('/inventory/warehouses?status=inactive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('warehouses.data', 1)->where('warehouses.data.0.name', 'Other WH'));

        $this->get('/inventory/categories?search=Findable')->assertOk()
            ->assertInertia(fn ($page) => $page->has('categories.data', 1)->where('categories.data.0.name', 'Findable Cat'));
        $this->get('/inventory/categories?status=inactive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('categories.data', 1)->where('categories.data.0.name', 'Other Cat'));

        $this->get('/inventory/adjustment-reasons?search=findable_reason')->assertOk()
            ->assertInertia(fn ($page) => $page->has('reasons.data', 1)->where('reasons.data.0.code', 'findable_reason'));
        $this->get('/inventory/adjustment-reasons?status=inactive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('reasons.data', 1)->where('reasons.data.0.code', 'other_reason'));
    }
}
