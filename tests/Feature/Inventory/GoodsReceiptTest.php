<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Models\UomConversion;
use App\Modules\Inventory\Services\GoodsReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3D — Goods Receipt: draft CRUD plus post() (the only action that touches the ledger). */
class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    public function test_admin_can_crud_a_draft_goods_receipt(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $productId = null;
        $uomId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId, &$productId, &$uomId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $locationId = $this->makeLocation($warehouse)->id;
            $product = $this->makeProduct();
            $productId = $product->id;
            $uomId = $product->base_uom_id;
        });

        $this->get('/inventory/goods-receipts')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/GoodsReceipts/Index'));
        $this->get('/inventory/goods-receipts/create')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/GoodsReceipts/Create'));

        $this->post('/inventory/goods-receipts', [
            'warehouse_id' => $warehouseId,
            'receipt_date' => now()->toDateString(),
            'reference_number' => 'GR-1',
            'lines' => [[
                'product_id' => $productId, 'qty' => 10, 'uom_id' => $uomId, 'unit_cost' => 5,
                'destination_location_id' => $locationId,
            ]],
        ])->assertRedirect();

        $receiptId = null;
        $tenant->run(function () use (&$receiptId) {
            $receipt = GoodsReceipt::query()->where('reference_number', 'GR-1')->first();
            $this->assertNotNull($receipt);
            $this->assertSame(1, $receipt->lines()->count());
            $receiptId = $receipt->id;
        });

        $this->get("/inventory/goods-receipts/{$receiptId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/GoodsReceipts/Edit')->where('receipt.reference_number', 'GR-1'));

        $this->put("/inventory/goods-receipts/{$receiptId}", [
            'warehouse_id' => $warehouseId, 'receipt_date' => now()->toDateString(), 'reference_number' => 'GR-1 (updated)',
            'lines' => [[
                'product_id' => $productId, 'qty' => 15, 'uom_id' => $uomId, 'unit_cost' => 6,
                'destination_location_id' => $locationId,
            ]],
        ])->assertRedirect();

        $tenant->run(function () use ($receiptId) {
            $receipt = GoodsReceipt::query()->find($receiptId);
            $this->assertSame('GR-1 (updated)', $receipt->reference_number);
            $this->assertSame('15.0000', $receipt->lines()->first()->qty);
        });

        $this->delete("/inventory/goods-receipts/{$receiptId}")->assertRedirect(route('inventory.goodsReceipts.index'));
        $tenant->run(function () use ($receiptId) {
            $this->assertNull(GoodsReceipt::query()->find($receiptId));
        });
    }

    public function test_index_filters_by_search_status_warehouse_and_sorts(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $tenant->run(function () use (&$warehouseId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'reference_number' => 'FIND-ME', 'status' => GoodsReceipt::STATUS_DRAFT]);
            GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'reference_number' => 'OTHER', 'status' => GoodsReceipt::STATUS_POSTED]);
        });

        $this->get('/inventory/goods-receipts?search=FIND-ME')->assertOk()
            ->assertInertia(fn ($page) => $page->has('receipts.data', 1)->where('receipts.data.0.reference_number', 'FIND-ME'));

        $this->get('/inventory/goods-receipts?status=posted')->assertOk()
            ->assertInertia(fn ($page) => $page->has('receipts.data', 1)->where('receipts.data.0.reference_number', 'OTHER'));

        $this->get("/inventory/goods-receipts?warehouse_id={$warehouseId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('receipts.data', 2));

        $this->get('/inventory/goods-receipts?sort=reference_number&direction=asc&per_page=5')->assertOk()
            ->assertInertia(fn ($page) => $page->where('receipts.data.0.reference_number', 'FIND-ME'));
    }

    public function test_store_validation_rejects_invalid_warehouse_product_uom_and_empty_lines(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $productId = $this->makeProduct()->id;
        });

        $this->post('/inventory/goods-receipts', ['warehouse_id' => 999999, 'receipt_date' => now()->toDateString(), 'lines' => []])
            ->assertSessionHasErrors(['warehouse_id', 'lines']);

        $warehouseId = null;
        $tenant->run(function () use (&$warehouseId) {
            $warehouseId = $this->makeWarehouse()->id;
        });

        $this->post('/inventory/goods-receipts', [
            'warehouse_id' => $warehouseId, 'receipt_date' => now()->toDateString(),
            'lines' => [['product_id' => 999999, 'qty' => 1, 'uom_id' => 999999, 'unit_cost' => 1]],
        ])->assertSessionHasErrors(['lines.0.product_id', 'lines.0.uom_id']);
    }

    public function test_update_validation_rejects_invalid_warehouse_product_and_uom(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $receiptId = null;
        $tenant->run(function () use (&$receiptId) {
            $warehouse = $this->makeWarehouse();
            $receiptId = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT])->id;
        });

        $this->put("/inventory/goods-receipts/{$receiptId}", ['warehouse_id' => 999999, 'receipt_date' => now()->toDateString(), 'lines' => []])
            ->assertSessionHasErrors(['warehouse_id', 'lines']);

        $this->put("/inventory/goods-receipts/{$receiptId}", [
            'warehouse_id' => 999999, 'receipt_date' => now()->toDateString(),
            'lines' => [['product_id' => 999999, 'qty' => 1, 'uom_id' => 999999, 'unit_cost' => 1]],
        ])->assertSessionHasErrors(['lines.0.product_id', 'lines.0.uom_id']);
    }

    public function test_posting_a_receipt_creates_ledger_valuation_layer_and_balance(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $receiptId = null;
        $warehouseId = null;
        $locationId = null;
        $productId = null;
        $tenant->run(function () use (&$receiptId, &$warehouseId, &$locationId, &$productId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $product = $this->makeProduct('RECV-1', ['costing_method' => Product::COSTING_FIFO]);
            $productId = $product->id;

            $receipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);
            $receipt->lines()->create([
                'product_id' => $product->id, 'qty' => 10, 'uom_id' => $product->base_uom_id, 'unit_cost' => 4,
                'destination_location_id' => $location->id,
            ]);
            $receiptId = $receipt->id;
        });

        $this->patch("/inventory/goods-receipts/{$receiptId}/post")->assertRedirect(route('inventory.goodsReceipts.edit', $receiptId));

        $tenant->run(function () use ($receiptId, $warehouseId, $locationId, $productId) {
            $receipt = GoodsReceipt::query()->find($receiptId);
            $this->assertSame(GoodsReceipt::STATUS_POSTED, $receipt->status);
            $this->assertNotNull($receipt->posted_at);

            $ledger = StockLedger::query()->where('subject_type', 'inventory.goods_receipts')->where('subject_id', (string) $receiptId)->first();
            $this->assertNotNull($ledger);
            $this->assertSame(StockLedger::TYPE_RECEIPT, $ledger->movement_type);
            $this->assertSame('10.0000', $ledger->qty);

            $layer = StockValuationLayer::query()->where('product_id', $productId)->first();
            $this->assertNotNull($layer);
            $this->assertSame('10.0000', $layer->remaining_qty);

            $balance = StockBalance::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('location_id', $locationId)->first();
            $this->assertSame('10.0000', $balance->qty_on_hand);
        });

        // Draft-only actions are now blocked.
        $this->put("/inventory/goods-receipts/{$receiptId}", ['warehouse_id' => $warehouseId, 'receipt_date' => now()->toDateString(), 'lines' => [['product_id' => $productId, 'qty' => 1, 'uom_id' => 1, 'unit_cost' => 1]]])
            ->assertSessionHasErrors(['status']);
        $this->delete("/inventory/goods-receipts/{$receiptId}")->assertSessionHasErrors(['status']);
        $this->patch("/inventory/goods-receipts/{$receiptId}/post")->assertSessionHasErrors(['status']);
    }

    public function test_posting_is_blocked_with_no_lines_inactive_product_missing_or_foreign_destination(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $emptyReceiptId = null;
        $inactiveReceiptId = null;
        $noDestReceiptId = null;
        $foreignDestReceiptId = null;
        $tenant->run(function () use (&$emptyReceiptId, &$inactiveReceiptId, &$noDestReceiptId, &$foreignDestReceiptId) {
            $warehouse = $this->makeWarehouse();
            $otherWarehouse = $this->makeWarehouse('Other WH');
            $foreignLocation = $this->makeLocation($otherWarehouse, 'FOREIGN');
            $location = $this->makeLocation($warehouse);

            $emptyReceiptId = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT])->id;

            $inactiveProduct = $this->makeProduct('INACTIVE-1');
            $inactiveProduct->update(['is_active' => false]);
            $inactiveReceipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);
            $inactiveReceipt->lines()->create(['product_id' => $inactiveProduct->id, 'qty' => 1, 'uom_id' => $inactiveProduct->base_uom_id, 'unit_cost' => 1, 'destination_location_id' => $location->id]);
            $inactiveReceiptId = $inactiveReceipt->id;

            $product = $this->makeProduct('NODEST-1');
            $noDestReceipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);
            $noDestReceipt->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'unit_cost' => 1, 'destination_location_id' => null]);
            $noDestReceiptId = $noDestReceipt->id;

            $foreignDestReceipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);
            $foreignDestReceipt->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'unit_cost' => 1, 'destination_location_id' => $foreignLocation->id]);
            $foreignDestReceiptId = $foreignDestReceipt->id;
        });

        $this->patch("/inventory/goods-receipts/{$emptyReceiptId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/goods-receipts/{$inactiveReceiptId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/goods-receipts/{$noDestReceiptId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/goods-receipts/{$foreignDestReceiptId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_posting_a_batch_tracked_line_resolves_the_lot_and_requires_one(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $productId = null;
        $uomId = null;
        $missingLotReceiptId = null;
        $withLotReceiptId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId, &$productId, &$uomId, &$missingLotReceiptId, &$withLotReceiptId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $product = $this->makeProduct('BATCH-1', ['tracking_mode' => Product::TRACKING_BATCH]);
            $productId = $product->id;
            $uomId = $product->base_uom_id;

            $missingLotReceipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);
            $missingLotReceipt->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'unit_cost' => 1, 'destination_location_id' => $location->id, 'batch_id' => null]);
            $missingLotReceiptId = $missingLotReceipt->id;
        });

        $this->patch("/inventory/goods-receipts/{$missingLotReceiptId}/post")->assertSessionHasErrors(['lines']);

        // The draft-save path (store()) resolves a free-text lot number into a batch_id.
        $this->post('/inventory/goods-receipts', [
            'warehouse_id' => $warehouseId, 'receipt_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $productId, 'qty' => 5, 'uom_id' => $uomId, 'unit_cost' => 2,
                'destination_location_id' => $locationId, 'batch_number' => 'LOT-001',
            ]],
        ])->assertRedirect();

        $tenant->run(function () use (&$withLotReceiptId, $productId) {
            $batch = StockBatch::query()->where('product_id', $productId)->where('batch_number', 'LOT-001')->first();
            $this->assertNotNull($batch);
            $withLotReceiptId = GoodsReceiptLine::query()->where('batch_id', $batch->id)->value('goods_receipt_id');
        });

        $this->patch("/inventory/goods-receipts/{$withLotReceiptId}/post")->assertRedirect();
        $tenant->run(function () use ($productId) {
            $this->assertSame(1, StockValuationLayer::query()->where('product_id', $productId)->whereNotNull('batch_id')->count());
        });
    }

    public function test_posting_a_serial_tracked_line_requires_matching_serial_count(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $mismatchReceiptId = null;
        $okReceiptId = null;
        $productId = null;
        $tenant->run(function () use (&$mismatchReceiptId, &$okReceiptId, &$productId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('SERIAL-1', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $productId = $product->id;

            $mismatchReceipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);
            $mismatchReceipt->lines()->create([
                'product_id' => $product->id, 'qty' => 2, 'uom_id' => $product->base_uom_id, 'unit_cost' => 1,
                'destination_location_id' => $location->id, 'serial_numbers' => ['SN-1'],
            ]);
            $mismatchReceiptId = $mismatchReceipt->id;

            $okReceipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);
            $okReceipt->lines()->create([
                'product_id' => $product->id, 'qty' => 2, 'uom_id' => $product->base_uom_id, 'unit_cost' => 1,
                'destination_location_id' => $location->id, 'serial_numbers' => ['SN-1', 'SN-2'],
            ]);
            $okReceiptId = $okReceipt->id;
        });

        $this->patch("/inventory/goods-receipts/{$mismatchReceiptId}/post")->assertSessionHasErrors(['lines']);

        $this->patch("/inventory/goods-receipts/{$okReceiptId}/post")->assertRedirect();
        $tenant->run(function () use ($productId) {
            $this->assertSame(2, StockSerial::query()->where('product_id', $productId)->where('status', StockSerial::STATUS_IN_STOCK)->count());
        });
    }

    public function test_posting_rejects_a_serial_number_already_on_record(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $dupeReceiptId = null;
        $tenant->run(function () use (&$dupeReceiptId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('SERIAL-DUPE', ['tracking_mode' => Product::TRACKING_SERIAL]);

            StockSerial::query()->create(['product_id' => $product->id, 'serial_number' => 'SN-EXISTING', 'status' => StockSerial::STATUS_IN_STOCK, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id]);

            $receipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);
            $receipt->lines()->create([
                'product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'unit_cost' => 1,
                'destination_location_id' => $location->id, 'serial_numbers' => ['SN-EXISTING'],
            ]);
            $dupeReceiptId = $receipt->id;
        });

        $this->patch("/inventory/goods-receipts/{$dupeReceiptId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_posting_rejects_a_fractional_qty_on_a_serial_tracked_line(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $receiptId = null;
        $tenant->run(function () use (&$receiptId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('SERIAL-FRACTIONAL', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $looseUom = $this->makeUom('LOOSE', 'Loose Pack');
            UomConversion::query()->create(['product_id' => $product->id, 'uom_id' => $looseUom->id, 'conversion_factor' => 1.5]);

            $receipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);
            $receipt->lines()->create([
                'product_id' => $product->id, 'qty' => 1, 'uom_id' => $looseUom->id, 'unit_cost' => 1,
                'destination_location_id' => $location->id, 'serial_numbers' => ['SN-FRAC-1'],
            ]);
            $receiptId = $receipt->id;
        });

        $this->patch("/inventory/goods-receipts/{$receiptId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_updating_a_receipt_silently_drops_a_blank_line(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct();
            $receipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);

            app(GoodsReceiptService::class)->update($receipt, [
                'warehouse_id' => $warehouse->id, 'receipt_date' => now()->toDateString(),
                'lines' => [
                    ['product_id' => $product->id, 'qty' => 2, 'uom_id' => $product->base_uom_id, 'unit_cost' => 1, 'destination_location_id' => $location->id],
                    ['product_id' => null, 'qty' => null, 'uom_id' => $product->base_uom_id, 'unit_cost' => 1],
                ],
            ]);

            $this->assertSame(1, $receipt->lines()->count());
        });
    }
}
