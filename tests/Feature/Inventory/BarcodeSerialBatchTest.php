<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\LocationBarcode;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBarcode;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\Inventory\Services\BatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3K/§3L/§3M — Barcode scan resolver, Batch/Lot master data + expiry Status Rail, and the read-only Serial browse. */
class BarcodeSerialBatchTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_barcode_scan_resolves_a_product_and_a_location_and_404s_when_not_found(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $tenant->run(function () use (&$warehouseId) {
            $product = $this->makeProduct('SCAN-1');
            ProductBarcode::query()->create(['product_id' => $product->id, 'barcode' => 'SCAN-CODE-1', 'type' => ProductBarcode::TYPE_PRIMARY, 'unit_multiplier' => 1]);

            $inactive = $this->makeProduct('SCAN-INACTIVE');
            $inactive->update(['is_active' => false]);
            ProductBarcode::query()->create(['product_id' => $inactive->id, 'barcode' => 'SCAN-INACTIVE-CODE', 'type' => ProductBarcode::TYPE_PRIMARY, 'unit_multiplier' => 1]);

            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse, 'SCAN-LOC');
            LocationBarcode::query()->create(['location_id' => $location->id, 'barcode' => 'LOC-CODE-1']);
        });

        $this->get('/inventory/barcode-scan?code=SCAN-CODE-1&context=product')
            ->assertOk()->assertJson(['found' => true, 'sku' => 'SCAN-1']);

        $this->get('/inventory/barcode-scan?code=NOPE&context=product')->assertNotFound()->assertJson(['found' => false]);

        // Barcode exists but belongs to a now-inactive product -> treated as not found.
        $this->get('/inventory/barcode-scan?code=SCAN-INACTIVE-CODE&context=product')->assertNotFound()->assertJson(['found' => false]);

        $this->get('/inventory/barcode-scan?code=LOC-CODE-1&context=location')
            ->assertOk()->assertJson(['found' => true, 'code' => 'SCAN-LOC']);

        // Warehouse filter narrows the location lookup — a foreign warehouse_id makes it miss.
        $this->get('/inventory/barcode-scan?code=LOC-CODE-1&context=location&warehouse_id=999999')
            ->assertNotFound()->assertJson(['found' => false]);

        $this->get("/inventory/barcode-scan?code=LOC-CODE-1&context=location&warehouse_id={$warehouseId}")
            ->assertOk()->assertJson(['found' => true]);
    }

    public function test_barcode_scan_validates_required_code_and_context(): void
    {
        $this->loginAsInventoryAdmin();

        $this->get('/inventory/barcode-scan?context=product')->assertSessionHasErrors(['code']);
        $this->get('/inventory/barcode-scan?code=X&context=bogus')->assertSessionHasErrors(['context']);
    }

    public function test_admin_can_crud_a_batch_and_delete_is_blocked_once_it_has_ledger_history(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $productId = $this->makeProduct('BATCH-CRUD', ['tracking_mode' => Product::TRACKING_BATCH])->id;
        });

        $this->get('/inventory/batches')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Batches/Index'));
        $this->get('/inventory/batches/create')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Batches/Create'));

        $this->post('/inventory/batches', [
            'product_id' => $productId, 'batch_number' => 'LOT-CRUD-1', 'expiry_date' => now()->addYear()->toDateString(),
        ])->assertRedirect(route('inventory.batches.index'));

        $batchId = null;
        $tenant->run(function () use (&$batchId) {
            $batch = StockBatch::query()->where('batch_number', 'LOT-CRUD-1')->first();
            $this->assertNotNull($batch);
            $batchId = $batch->id;
        });

        $this->post('/inventory/batches', ['product_id' => 999999, 'batch_number' => 'BAD'])->assertSessionHasErrors(['product_id']);

        $this->get("/inventory/batches/{$batchId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Batches/Edit')->where('batch.batch_number', 'LOT-CRUD-1'));

        $this->put("/inventory/batches/{$batchId}", ['batch_number' => 'LOT-CRUD-1 (renamed)'])->assertRedirect(route('inventory.batches.index'));
        $tenant->run(function () use ($batchId) {
            $this->assertSame('LOT-CRUD-1 (renamed)', StockBatch::query()->find($batchId)->batch_number);
        });

        // A batch with no movement history yet deletes cleanly.
        $freeBatchId = null;
        $tenant->run(function () use (&$freeBatchId, $productId) {
            $freeBatchId = app(BatchService::class)->resolve($productId, 'LOT-FREE')->id;
        });
        $this->delete("/inventory/batches/{$freeBatchId}")->assertRedirect(route('inventory.batches.index'));
        $tenant->run(function () use ($freeBatchId) {
            $this->assertNull(StockBatch::query()->find($freeBatchId));
        });

        $tenant->run(function () use ($batchId, $productId) {
            $warehouse = $this->makeWarehouse();
            StockLedger::query()->create([
                'product_id' => $productId, 'warehouse_id' => $warehouse->id, 'location_id' => $this->makeLocation($warehouse)->id,
                'batch_id' => $batchId, 'movement_type' => StockLedger::TYPE_RECEIPT, 'qty' => 5, 'unit_cost' => 1, 'total_value' => 5,
                'movement_date' => now(),
            ]);
        });

        $this->delete("/inventory/batches/{$batchId}")->assertSessionHasErrors(['batch_number']);
    }

    public function test_batch_index_filters_by_search_product_and_expiry_status_with_status_rail(): void
    {
        Carbon::setTestNow('2026-06-15');
        $tenant = $this->loginAsInventoryAdmin();

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $product = $this->makeProduct('BATCH-FILTER', ['tracking_mode' => Product::TRACKING_BATCH]);
            $productId = $product->id;

            StockBatch::query()->create(['product_id' => $product->id, 'batch_number' => 'EXPIRED-LOT', 'expiry_date' => '2026-06-01']);
            StockBatch::query()->create(['product_id' => $product->id, 'batch_number' => 'SOON-LOT', 'expiry_date' => '2026-06-20']);
            StockBatch::query()->create(['product_id' => $product->id, 'batch_number' => 'FRESH-LOT', 'expiry_date' => '2027-01-01']);
            StockBatch::query()->create(['product_id' => $product->id, 'batch_number' => 'NO-EXPIRY-LOT']);
        });

        $this->get('/inventory/batches?search=SOON-LOT')->assertOk()
            ->assertInertia(fn ($page) => $page->has('batches.data', 1)->where('batches.data.0.batch_number', 'SOON-LOT')->where('batches.data.0.status_rail', 'warning'));

        $this->get("/inventory/batches?product_id={$productId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('batches.data', 4));

        $this->get('/inventory/batches?expiry_status=expired')->assertOk()
            ->assertInertia(fn ($page) => $page->has('batches.data', 1)->where('batches.data.0.batch_number', 'EXPIRED-LOT')->where('batches.data.0.status_rail', 'danger'));

        $this->get('/inventory/batches?expiry_status=expiring_soon')->assertOk()
            ->assertInertia(fn ($page) => $page->has('batches.data', 1)->where('batches.data.0.batch_number', 'SOON-LOT'));

        $this->get('/inventory/batches?search=FRESH-LOT')->assertOk()
            ->assertInertia(fn ($page) => $page->where('batches.data.0.status_rail', 'success'));

        $this->get('/inventory/batches?search=NO-EXPIRY-LOT')->assertOk()
            ->assertInertia(fn ($page) => $page->where('batches.data.0.status_rail', ''));

        $this->get('/inventory/batches?sort=batch_number&direction=asc&per_page=5')->assertOk();
    }

    public function test_serial_index_filters_by_search_product_and_status(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('SERIAL-BROWSE', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $productId = $product->id;

            StockSerial::query()->create(['product_id' => $product->id, 'serial_number' => 'SN-A', 'status' => StockSerial::STATUS_IN_STOCK, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id]);
            StockSerial::query()->create(['product_id' => $product->id, 'serial_number' => 'SN-B', 'status' => StockSerial::STATUS_ISSUED]);
        });

        $this->get('/inventory/serials?search=SN-A')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Serials/Index')->has('serials.data', 1)->where('serials.data.0.serial_number', 'SN-A'));

        $this->get("/inventory/serials?product_id={$productId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('serials.data', 2));

        $this->get('/inventory/serials?status=issued')->assertOk()
            ->assertInertia(fn ($page) => $page->has('serials.data', 1)->where('serials.data.0.serial_number', 'SN-B'));

        $this->get('/inventory/serials?sort=serial_number&direction=desc&per_page=5')->assertOk()
            ->assertInertia(fn ($page) => $page->where('serials.data.0.serial_number', 'SN-B'));
    }

    public function test_store_batch_validation_rejects_a_duplicate_lot_number_for_the_same_product(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $productId = $this->makeProduct('BATCH-DUPE', ['tracking_mode' => Product::TRACKING_BATCH])->id;
            StockBatch::query()->create(['product_id' => $productId, 'batch_number' => 'LOT-TAKEN']);
        });

        $this->post('/inventory/batches', ['product_id' => $productId, 'batch_number' => 'LOT-TAKEN'])
            ->assertSessionHasErrors(['batch_number']);
    }

    public function test_batch_service_resolve_returns_the_existing_batch_for_a_known_lot_number(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('BATCH-RESOLVE', ['tracking_mode' => Product::TRACKING_BATCH]);
            $service = app(BatchService::class);

            $first = $service->resolve($product->id, 'LOT-RESOLVE', '2027-01-01');
            $second = $service->resolve($product->id, 'LOT-RESOLVE', '2099-12-31');

            // Same row returned both times — the second call's differing expiry is ignored,
            // an existing lot's metadata is never silently overwritten.
            $this->assertSame($first->id, $second->id);
            $this->assertSame('2027-01-01', $second->expiry_date->toDateString());
        });
    }
}
