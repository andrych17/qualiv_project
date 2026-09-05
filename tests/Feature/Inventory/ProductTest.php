<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBarcode;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Models\UomConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3B — Product master data: CRUD, barcodes/UoM-conversion sync, and the open-valuation-layer guard on costing/tracking changes. */
class ProductTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    public function test_admin_can_crud_a_product_with_barcodes_and_uom_conversions(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $uomId = null;
        $altUomId = null;
        $categoryId = null;
        $tenant->run(function () use (&$uomId, &$altUomId, &$categoryId) {
            $uomId = $this->makeUom('EA', 'Each')->id;
            $altUomId = $this->makeUom('BOX', 'Box')->id;
            $categoryId = $this->makeCategory('Widgets')->id;
        });

        $this->get('/inventory/products')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Products/Index'));
        $this->get('/inventory/products/create')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Products/Create'));

        $this->post('/inventory/products', [
            'sku' => 'WID-1',
            'name' => 'Widget',
            'category_id' => $categoryId,
            'base_uom_id' => $uomId,
            'costing_method' => Product::COSTING_FIFO,
            'tracking_mode' => Product::TRACKING_NONE,
            'barcodes' => [['barcode' => '111222333', 'type' => ProductBarcode::TYPE_PRIMARY, 'unit_multiplier' => 1]],
            'uom_conversions' => [['uom_id' => $altUomId, 'conversion_factor' => 12]],
        ])->assertRedirect(route('inventory.products.index'));

        $productId = null;
        $tenant->run(function () use (&$productId, $altUomId) {
            $product = Product::query()->where('sku', 'WID-1')->first();
            $this->assertNotNull($product);
            $this->assertSame(1, ProductBarcode::query()->where('product_id', $product->id)->count());
            $this->assertSame(1, UomConversion::query()->where('product_id', $product->id)->where('uom_id', $altUomId)->count());
            $productId = $product->id;
        });

        $this->get("/inventory/products/{$productId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Products/Edit')->where('product.sku', 'WID-1'));

        $this->put("/inventory/products/{$productId}", [
            'sku' => 'WID-1', 'name' => 'Widget (renamed)', 'base_uom_id' => $uomId,
            'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE, 'is_active' => true,
        ])->assertRedirect(route('inventory.products.index'));

        $tenant->run(function () use ($productId) {
            $product = Product::query()->find($productId);
            $this->assertSame('Widget (renamed)', $product->name);
            // Update omitted barcodes/uom_conversions -> both synced away, matching real form behaviour.
            $this->assertSame(0, ProductBarcode::query()->where('product_id', $productId)->count());
            $this->assertSame(0, UomConversion::query()->where('product_id', $productId)->count());
        });

        $this->delete("/inventory/products/{$productId}")->assertRedirect(route('inventory.products.index'));
        $tenant->run(function () use ($productId) {
            // destroy() deactivates, never deletes — stock_ledger FKs against this row.
            $product = Product::query()->find($productId);
            $this->assertNotNull($product);
            $this->assertFalse($product->is_active);
        });
    }

    public function test_bulk_destroy_deactivates_selected_products(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makeProduct('BULK-1')->id;
            $ids[] = $this->makeProduct('BULK-2')->id;
        });

        $this->delete('/inventory/products/bulk-destroy', ['ids' => $ids])->assertRedirect();

        $tenant->run(function () use ($ids) {
            $this->assertSame(2, Product::query()->whereIn('id', $ids)->where('is_active', false)->count());
        });
    }

    public function test_index_filters_by_search_status_category_and_sorts(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $categoryId = null;
        $tenant->run(function () use (&$categoryId) {
            $categoryId = $this->makeCategory('Filtered')->id;
            $this->makeProduct('AAA-1', ['name' => 'Alpha', 'category_id' => $categoryId]);
            $this->makeProduct('ZZZ-1', ['name' => 'Zulu'])->update(['is_active' => false]);
        });

        $this->get('/inventory/products?search=Alpha')->assertOk()
            ->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.sku', 'AAA-1'));

        $this->get('/inventory/products?status=inactive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.sku', 'ZZZ-1'));

        $this->get("/inventory/products?category_id={$categoryId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.sku', 'AAA-1'));

        $this->get('/inventory/products?sort=sku&direction=asc&per_page=5')->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.data.0.sku', 'AAA-1'));
    }

    public function test_store_validation_rejects_duplicate_sku_invalid_refs_and_barcode_conflicts(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $uomId = null;
        $tenant->run(function () use (&$uomId) {
            $uomId = $this->makeUom()->id;
            $this->makeProduct('DUPE-SKU');
            $existing = $this->makeProduct('OWNS-BARCODE');
            ProductBarcode::query()->create(['product_id' => $existing->id, 'barcode' => 'TAKEN-CODE', 'type' => ProductBarcode::TYPE_PRIMARY, 'unit_multiplier' => 1]);
        });

        $this->post('/inventory/products', ['sku' => 'DUPE-SKU', 'name' => 'X', 'base_uom_id' => $uomId])
            ->assertSessionHasErrors(['sku']);

        $this->post('/inventory/products', ['sku' => 'NEW-1', 'name' => 'X', 'base_uom_id' => $uomId, 'category_id' => 999999])
            ->assertSessionHasErrors(['category_id']);

        $this->post('/inventory/products', ['sku' => 'NEW-2', 'name' => 'X', 'base_uom_id' => 999999])
            ->assertSessionHasErrors(['base_uom_id']);

        $this->post('/inventory/products', [
            'sku' => 'NEW-3', 'name' => 'X', 'base_uom_id' => $uomId,
            'barcodes' => [['barcode' => 'SAME', 'type' => 'primary'], ['barcode' => 'SAME', 'type' => 'alternate']],
        ])->assertSessionHasErrors(['barcodes']);

        $this->post('/inventory/products', [
            'sku' => 'NEW-4', 'name' => 'X', 'base_uom_id' => $uomId,
            'barcodes' => [['barcode' => 'ONE', 'type' => 'primary'], ['barcode' => 'TWO', 'type' => 'primary']],
        ])->assertSessionHasErrors(['barcodes']);

        $this->post('/inventory/products', [
            'sku' => 'NEW-5', 'name' => 'X', 'base_uom_id' => $uomId,
            'barcodes' => [['barcode' => 'TAKEN-CODE', 'type' => 'primary']],
        ])->assertSessionHasErrors(['barcodes']);
    }

    public function test_store_validation_rejects_base_uom_as_conversion_and_duplicate_conversions(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $uomId = null;
        $altUomId = null;
        $tenant->run(function () use (&$uomId, &$altUomId) {
            $uomId = $this->makeUom()->id;
            $altUomId = $this->makeUom('BOX', 'Box')->id;
        });

        $this->post('/inventory/products', [
            'sku' => 'CONV-1', 'name' => 'X', 'base_uom_id' => $uomId,
            'uom_conversions' => [['uom_id' => $uomId, 'conversion_factor' => 2]],
        ])->assertSessionHasErrors(['uom_conversions']);

        $this->post('/inventory/products', [
            'sku' => 'CONV-2', 'name' => 'X', 'base_uom_id' => $uomId,
            'uom_conversions' => [['uom_id' => $altUomId, 'conversion_factor' => 2], ['uom_id' => $altUomId, 'conversion_factor' => 3]],
        ])->assertSessionHasErrors(['uom_conversions']);
    }

    public function test_update_validation_rejects_duplicate_sku_excluding_self_and_invalid_refs(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $productId = null;
        $uomId = null;
        $tenant->run(function () use (&$productId, &$uomId) {
            $this->makeProduct('TAKEN-SKU');
            $product = $this->makeProduct('OWN-SKU');
            $productId = $product->id;
            $uomId = $product->base_uom_id;
        });

        // Self-collision on its own current SKU is fine (excluded by id).
        $this->put("/inventory/products/{$productId}", ['sku' => 'OWN-SKU', 'name' => 'Renamed', 'base_uom_id' => $uomId])
            ->assertRedirect();

        $this->put("/inventory/products/{$productId}", ['sku' => 'TAKEN-SKU', 'name' => 'X', 'base_uom_id' => $uomId])
            ->assertSessionHasErrors(['sku']);

        $this->put("/inventory/products/{$productId}", ['sku' => 'OWN-SKU', 'name' => 'X', 'base_uom_id' => $uomId, 'category_id' => 999999])
            ->assertSessionHasErrors(['category_id']);

        $this->put("/inventory/products/{$productId}", ['sku' => 'OWN-SKU', 'name' => 'X', 'base_uom_id' => 999999])
            ->assertSessionHasErrors(['base_uom_id']);
    }

    public function test_update_blocks_costing_method_change_while_valuation_layers_are_open(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $productId = null;
        $uomId = null;
        $tenant->run(function () use (&$productId, &$uomId) {
            $product = $this->makeProduct('LAYERED', ['costing_method' => Product::COSTING_FIFO]);
            $uomId = $product->base_uom_id;
            $warehouse = $this->makeWarehouse();
            StockValuationLayer::query()->create([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
                'unit_cost' => 10, 'qty' => 5, 'remaining_qty' => 5,
            ]);
            $productId = $product->id;
        });

        $this->put("/inventory/products/{$productId}", [
            'sku' => 'LAYERED', 'name' => 'X', 'base_uom_id' => $uomId, 'costing_method' => Product::COSTING_AVERAGE,
        ])->assertSessionHasErrors(['costing_method']);

        $this->put("/inventory/products/{$productId}", [
            'sku' => 'LAYERED', 'name' => 'X', 'base_uom_id' => $uomId, 'tracking_mode' => Product::TRACKING_BATCH,
        ])->assertSessionHasErrors(['tracking_mode']);

        // Unrelated field changes are still allowed with the layer open.
        $this->put("/inventory/products/{$productId}", [
            'sku' => 'LAYERED', 'name' => 'Renamed while open', 'base_uom_id' => $uomId, 'costing_method' => Product::COSTING_FIFO,
        ])->assertRedirect();
    }

    public function test_update_allows_costing_method_change_once_layers_are_fully_consumed(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $productId = null;
        $uomId = null;
        $tenant->run(function () use (&$productId, &$uomId) {
            $product = $this->makeProduct('CONSUMED', ['costing_method' => Product::COSTING_FIFO]);
            $uomId = $product->base_uom_id;
            $warehouse = $this->makeWarehouse();
            StockValuationLayer::query()->create([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
                'unit_cost' => 10, 'qty' => 5, 'remaining_qty' => 0,
            ]);
            $productId = $product->id;
        });

        $this->put("/inventory/products/{$productId}", [
            'sku' => 'CONSUMED', 'name' => 'X', 'base_uom_id' => $uomId, 'costing_method' => Product::COSTING_AVERAGE,
        ])->assertRedirect();

        $tenant->run(function () use ($productId) {
            $this->assertSame(Product::COSTING_AVERAGE, Product::query()->find($productId)->costing_method);
        });
    }
}
