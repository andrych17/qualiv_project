<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\GoodsIssue;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Models\UomConversion;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\Costing\AverageStrategy;
use App\Modules\Inventory\Services\Costing\CostingService;
use App\Modules\Inventory\Services\Costing\FifoStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3J costing engines direct — Weighted Average re-pricing (FIFO is already exercised end-to-end by the Goods Receipt/Issue tests) and §3B's UoM-conversion path (a line entered in a non-base unit). */
class CostingDirectTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    public function test_average_costing_reprices_on_each_receipt_and_issues_at_the_blended_cost(): void
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
            $product = $this->makeProduct('AVG-1', ['costing_method' => Product::COSTING_AVERAGE]);
            $productId = $product->id;
            $uomId = $product->base_uom_id;
        });

        // First receipt: 10 units @ 10 -> layer becomes qty=10, unit_cost=10.
        $this->post('/inventory/goods-receipts', [
            'warehouse_id' => $warehouseId, 'receipt_date' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'qty' => 10, 'uom_id' => $uomId, 'unit_cost' => 10, 'destination_location_id' => $locationId]],
        ])->assertRedirect();
        $firstReceiptId = null;
        $tenant->run(function () use (&$firstReceiptId) {
            $firstReceiptId = GoodsReceipt::query()->first()->id;
        });
        $this->patch("/inventory/goods-receipts/{$firstReceiptId}/post")->assertRedirect();

        $tenant->run(function () use ($productId) {
            $layer = StockValuationLayer::query()->where('product_id', $productId)->first();
            $this->assertSame('10.0000', $layer->remaining_qty);
            $this->assertSame('10.000000', $layer->unit_cost);
        });

        // Second receipt: 10 units @ 20 -> re-priced average = (10*10 + 10*20) / 20 = 15.
        $this->post('/inventory/goods-receipts', [
            'warehouse_id' => $warehouseId, 'receipt_date' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'qty' => 10, 'uom_id' => $uomId, 'unit_cost' => 20, 'destination_location_id' => $locationId]],
        ])->assertRedirect();
        $secondReceiptId = null;
        $tenant->run(function () use (&$secondReceiptId) {
            $secondReceiptId = GoodsReceipt::query()->orderByDesc('id')->first()->id;
        });
        $this->patch("/inventory/goods-receipts/{$secondReceiptId}/post")->assertRedirect();

        $tenant->run(function () use ($productId) {
            // Still exactly ONE layer for this product/warehouse — re-priced in place, not a second row.
            $this->assertSame(1, StockValuationLayer::query()->where('product_id', $productId)->count());
            $layer = StockValuationLayer::query()->where('product_id', $productId)->first();
            $this->assertSame('20.0000', $layer->remaining_qty);
            $this->assertSame('15.000000', $layer->unit_cost);
        });

        // Issue 5 units -> consumes at the blended average (15), never at either original cost.
        $this->post('/inventory/goods-issues', [
            'warehouse_id' => $warehouseId, 'issue_date' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'qty' => 5, 'uom_id' => $uomId, 'source_location_id' => $locationId]],
        ])->assertRedirect();
        $issueId = null;
        $tenant->run(function () use (&$issueId) {
            $issueId = GoodsIssue::query()->first()->id;
        });
        $this->patch("/inventory/goods-issues/{$issueId}/post")->assertRedirect();

        $tenant->run(function () use ($productId) {
            $ledger = StockLedger::query()->where('product_id', $productId)->where('movement_type', StockLedger::TYPE_ISSUE)->first();
            $this->assertSame('15.000000', $ledger->unit_cost);
            $this->assertSame('-75.0000', $ledger->total_value);

            $layer = StockValuationLayer::query()->where('product_id', $productId)->first();
            $this->assertSame('15.0000', $layer->remaining_qty);
            // The average itself never changes on issue, only remaining_qty.
            $this->assertSame('15.000000', $layer->unit_cost);
        });
    }

    public function test_average_costing_keeps_separate_layers_per_batch(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $product = $this->makeProduct('AVG-BATCH', ['costing_method' => Product::COSTING_AVERAGE, 'tracking_mode' => Product::TRACKING_BATCH]);
            $strategy = app(CostingService::class)->strategyFor($product);

            $batchA = app(BatchService::class)->resolve($product->id, 'LOT-A');
            $batchB = app(BatchService::class)->resolve($product->id, 'LOT-B');

            $strategy->costReceipt($product->id, $warehouse->id, 10, 10, null, $batchA->id);
            $strategy->costReceipt($product->id, $warehouse->id, 10, 30, null, $batchB->id);

            // Two distinct layers, one per batch — receiving into A never touches B's average.
            $this->assertSame(2, StockValuationLayer::query()->where('product_id', $product->id)->count());
            $this->assertSame(10.0, $strategy->currentCost($product->id, $warehouse->id, $batchA->id));
            $this->assertSame(30.0, $strategy->currentCost($product->id, $warehouse->id, $batchB->id));

            // A second receipt into batch A re-prices only A's layer.
            $strategy->costReceipt($product->id, $warehouse->id, 10, 20, null, $batchA->id);
            $this->assertSame(15.0, $strategy->currentCost($product->id, $warehouse->id, $batchA->id));
            $this->assertSame(30.0, $strategy->currentCost($product->id, $warehouse->id, $batchB->id));

            $consumption = $strategy->costIssue($product->id, $warehouse->id, 5, $batchA->id);
            $this->assertSame(15.0, $consumption['unit_cost']);
            $this->assertSame(30.0, $strategy->currentCost($product->id, $warehouse->id, $batchB->id));
        });
    }

    public function test_costing_service_resolves_fifo_by_default_and_average_explicitly(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $costing = app(CostingService::class);

            $fifoProduct = $this->makeProduct('STRAT-FIFO', ['costing_method' => Product::COSTING_FIFO]);
            $this->assertInstanceOf(FifoStrategy::class, $costing->strategyFor($fifoProduct));

            $avgProduct = $this->makeProduct('STRAT-AVG', ['costing_method' => Product::COSTING_AVERAGE]);
            $this->assertInstanceOf(AverageStrategy::class, $costing->strategyFor($avgProduct));
        });
    }

    public function test_average_cost_issue_throws_when_not_enough_open_layers(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $product = $this->makeProduct('AVG-SHORT', ['costing_method' => Product::COSTING_AVERAGE]);
            $strategy = app(CostingService::class)->strategyFor($product);

            // No layer exists at all yet -> the "not enough" branch, not a null-pointer.
            try {
                $strategy->costIssue($product->id, $warehouse->id, 5);
                $this->fail('Expected a ValidationException when no valuation layer exists.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines', $e->errors());
            }

            $strategy->costReceipt($product->id, $warehouse->id, 3, 10, null);

            try {
                $strategy->costIssue($product->id, $warehouse->id, 100);
                $this->fail('Expected a ValidationException when the issue exceeds the open layer.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines', $e->errors());
            }

            $this->assertSame(0.0, $strategy->currentCost(999999, $warehouse->id));
        });
    }

    public function test_a_receipt_and_issue_line_entered_in_a_non_base_uom_converts_correctly(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $productId = null;
        $baseUomId = null;
        $boxUomId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId, &$productId, &$baseUomId, &$boxUomId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $locationId = $this->makeLocation($warehouse)->id;
            $product = $this->makeProduct('CONV-1');
            $productId = $product->id;
            $baseUomId = $product->base_uom_id;
            $boxUomId = $this->makeUom('BOX', 'Box')->id;
            UomConversion::query()->create(['product_id' => $product->id, 'uom_id' => $boxUomId, 'conversion_factor' => 12]);
        });

        // 2 boxes @ 24/box -> 24 base units @ 2/unit.
        $this->post('/inventory/goods-receipts', [
            'warehouse_id' => $warehouseId, 'receipt_date' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'qty' => 2, 'uom_id' => $boxUomId, 'unit_cost' => 24, 'destination_location_id' => $locationId]],
        ])->assertRedirect();
        $receiptId = null;
        $tenant->run(function () use (&$receiptId) {
            $receiptId = GoodsReceipt::query()->first()->id;
        });
        $this->patch("/inventory/goods-receipts/{$receiptId}/post")->assertRedirect();

        $tenant->run(function () use ($productId) {
            $ledger = StockLedger::query()->where('product_id', $productId)->first();
            $this->assertSame('24.0000', $ledger->qty);
            $this->assertSame('2.000000', $ledger->unit_cost);
        });

        // Issue 1 box -> 12 base units out.
        $this->post('/inventory/goods-issues', [
            'warehouse_id' => $warehouseId, 'issue_date' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'qty' => 1, 'uom_id' => $boxUomId, 'source_location_id' => $locationId]],
        ])->assertRedirect();
        $issueId = null;
        $tenant->run(function () use (&$issueId) {
            $issueId = GoodsIssue::query()->first()->id;
        });
        $this->patch("/inventory/goods-issues/{$issueId}/post")->assertRedirect();

        $tenant->run(function () use ($productId, $warehouseId, $locationId) {
            $issueLedger = StockLedger::query()->where('product_id', $productId)->where('movement_type', StockLedger::TYPE_ISSUE)->first();
            $this->assertSame('-12.0000', $issueLedger->qty);

            $balance = StockBalance::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('location_id', $locationId)->first();
            $this->assertSame('12.0000', $balance->qty_on_hand);
        });
    }

    public function test_posting_is_blocked_when_the_line_uom_has_no_conversion_set_up(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $productId = null;
        $unmappedUomId = null;
        $receiptId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId, &$productId, &$unmappedUomId, &$receiptId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $product = $this->makeProduct('NOCONV-1');
            $productId = $product->id;
            $unmappedUomId = $this->makeUom('DZ', 'Dozen')->id;

            $receipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);
            $receipt->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $unmappedUomId, 'unit_cost' => 1, 'destination_location_id' => $location->id]);
            $receiptId = $receipt->id;
        });

        $this->patch("/inventory/goods-receipts/{$receiptId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_fifo_issue_across_multiple_layers_stops_once_satisfied_by_an_earlier_layer(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $product = $this->makeProduct('FIFO-MULTI', ['costing_method' => Product::COSTING_FIFO]);
            $strategy = app(CostingService::class)->strategyFor($product);

            $strategy->costReceipt($product->id, $warehouse->id, 5, 10, null);
            $strategy->costReceipt($product->id, $warehouse->id, 5, 20, null);

            // Fully satisfied by the first (older, cheaper) layer alone -> the second is never touched.
            $consumption = $strategy->costIssue($product->id, $warehouse->id, 5);
            $this->assertSame(10.0, $consumption['unit_cost']);

            $layers = StockValuationLayer::query()->where('product_id', $product->id)->orderBy('id')->get();
            $this->assertSame('0.0000', $layers[0]->remaining_qty);
            $this->assertSame('5.0000', $layers[1]->remaining_qty);
        });
    }

    public function test_fifo_cost_issue_throws_when_not_enough_open_layers(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $product = $this->makeProduct('FIFO-SHORT', ['costing_method' => Product::COSTING_FIFO]);
            $strategy = app(CostingService::class)->strategyFor($product);
            $strategy->costReceipt($product->id, $warehouse->id, 3, 10, null);

            try {
                $strategy->costIssue($product->id, $warehouse->id, 100);
                $this->fail('Expected a ValidationException when the issue exceeds every open FIFO layer.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines', $e->errors());
            }
        });
    }
}
