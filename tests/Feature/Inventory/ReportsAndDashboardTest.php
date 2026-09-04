<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Adjustment;
use App\Modules\Inventory\Models\CycleCount;
use App\Modules\Inventory\Models\GoodsIssue;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Models\Transfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3A/§3H/§3I — Dashboard aggregate, Stock Card (ledger replay), and Inventory Valuation (live + as-of-date). All read-only reports. */
class ReportsAndDashboardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_surfaces_low_stock_expiring_batches_variances_and_pending_documents(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        Carbon::setTestNow('2026-06-15 10:00:00');

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);

            // Low stock (below reorder point but > 0).
            $lowProduct = $this->makeProduct('DASH-LOW', ['reorder_point' => 10]);
            StockBalance::query()->create(['product_id' => $lowProduct->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 3]);
            StockValuationLayer::query()->create(['product_id' => $lowProduct->id, 'warehouse_id' => $warehouse->id, 'unit_cost' => 5, 'qty' => 3, 'remaining_qty' => 3]);

            // Out of stock (reorder point set, zero balance rows at all).
            $this->makeProduct('DASH-OUT', ['reorder_point' => 5]);

            // A product with no reorder point set never counts as low/out.
            $this->makeProduct('DASH-NOREORDER', ['reorder_point' => 0]);

            // Expiring-soon batch with real on-hand stock.
            $expProduct = $this->makeProduct('DASH-EXPIRING', ['tracking_mode' => Product::TRACKING_BATCH]);
            $expBatch = StockBatch::query()->create(['product_id' => $expProduct->id, 'batch_number' => 'EXP-SOON', 'expiry_date' => '2026-06-25']);
            StockBalance::query()->create(['product_id' => $expProduct->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_id' => $expBatch->id, 'qty_on_hand' => 4]);

            // A batch that's expiring but has ZERO on-hand stock left -> excluded.
            $depletedBatch = StockBatch::query()->create(['product_id' => $expProduct->id, 'batch_number' => 'EXP-DEPLETED', 'expiry_date' => '2026-06-20']);
            StockBalance::query()->create(['product_id' => $expProduct->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_id' => $depletedBatch->id, 'qty_on_hand' => 0]);

            // Open count variance (draft Adjustment referencing "Cycle Count").
            $reason = $this->makeAdjustmentReason();
            Adjustment::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now(), 'reason_id' => $reason->id, 'reference' => 'Cycle Count #99', 'status' => Adjustment::STATUS_DRAFT]);

            // Pending documents of every kind.
            GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT, 'reference_number' => 'GR-DASH']);
            GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $location->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $this->makeLocation($warehouse, 'DEST')->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            Shipment::query()->create(['warehouse_id' => $warehouse->id, 'status' => Shipment::STATUS_PENDING]);

            // Open cycle count.
            CycleCount::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'status' => CycleCount::STATUS_IN_PROGRESS]);

            // A recent ledger movement.
            StockLedger::query()->create([
                'product_id' => $lowProduct->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
                'movement_type' => StockLedger::TYPE_RECEIPT, 'qty' => 3, 'unit_cost' => 5, 'total_value' => 15, 'movement_date' => now(),
            ]);
        });

        $this->get('/inventory/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Inventory/Dashboard')
            ->where('metrics.total_skus', 4)
            ->where('metrics.low_stock_count', 1)
            ->where('metrics.out_of_stock_count', 1)
            ->where('metrics.pending_receipts_count', 1)
            ->where('metrics.pending_shipments_count', 1)
            ->where('metrics.open_cycle_counts_count', 1)
            ->has('lowStock', 2)
            ->has('recentMovements', 1)
            ->has('pendingDocuments', 4)
            ->has('openCounts', 1)
            ->has('needsAttention'));
    }

    public function test_stock_card_shows_running_totals_reference_links_and_drift_flag(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $productId = null;
        $warehouseId = null;
        $locationId = null;
        $receiptId = null;
        $tenant->run(function () use (&$productId, &$warehouseId, &$locationId, &$receiptId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $product = $this->makeProduct('CARD-1');
            $productId = $product->id;

            $receipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => '2026-01-01', 'status' => GoodsReceipt::STATUS_POSTED]);
            $receiptId = $receipt->id;

            StockLedger::query()->create([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
                'movement_type' => StockLedger::TYPE_RECEIPT, 'qty' => 10, 'unit_cost' => 5, 'total_value' => 50,
                'subject_type' => 'inventory.goods_receipts', 'subject_id' => (string) $receipt->id, 'movement_date' => '2026-01-01',
            ]);
            StockLedger::query()->create([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
                'movement_type' => StockLedger::TYPE_ISSUE, 'qty' => -3, 'unit_cost' => 5, 'total_value' => -15,
                'subject_type' => 'inventory.goods_issues', 'subject_id' => '999', 'movement_date' => '2026-01-05',
            ]);

            // Deliberately don't create a matching StockBalance row -> drift should be flagged true.
        });

        $this->get('/inventory/stock-card')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/StockCard/Index')->where('product', null)->where('rows', null));

        $this->get("/inventory/stock-card?product_id={$productId}")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('product.sku', 'CARD-1')
                ->where('summary.ledger_qty', 7)
                ->where('summary.cached_qty', 0)
                ->where('summary.drifted', true)
                ->has('rows.data', 2)
                ->where('rows.data.0.running_qty', 10)
                ->where('rows.data.0.reference_label', "Receipt #{$receiptId}")
                ->where('rows.data.1.running_qty', 7)
                ->where('rows.data.1.reference_label', 'Issue #999'));

        $this->get("/inventory/stock-card?product_id={$productId}&movement_type=".StockLedger::TYPE_ISSUE)->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows.data', 1)->where('rows.data.0.movement_type', 'issue'));

        $this->get("/inventory/stock-card?product_id={$productId}&date_from=2026-01-03")->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows.data', 1));

        $this->get("/inventory/stock-card?product_id={$productId}&date_to=2026-01-02")->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows.data', 1));

        $this->get("/inventory/stock-card?product_id={$productId}&warehouse_id={$warehouseId}&location_id={$locationId}&per_page=1")->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows.data', 1));
    }

    public function test_valuation_live_and_as_of_date_report_with_filters(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $categoryId = null;
        $tenant->run(function () use (&$warehouseId, &$categoryId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $category = $this->makeCategory('Valuation Cat');
            $categoryId = $category->id;

            $product = $this->makeProduct('VAL-1', ['category_id' => $category->id]);
            StockValuationLayer::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'unit_cost' => 10, 'qty' => 4, 'remaining_qty' => 4]);
            StockValuationLayer::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'unit_cost' => 12, 'qty' => 6, 'remaining_qty' => 6]);
            // A fully-consumed layer must not contribute to the live total.
            StockValuationLayer::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'unit_cost' => 999, 'qty' => 1, 'remaining_qty' => 0]);

            $otherProduct = $this->makeProduct('VAL-OTHER');
            StockValuationLayer::query()->create(['product_id' => $otherProduct->id, 'warehouse_id' => $warehouse->id, 'unit_cost' => 3, 'qty' => 2, 'remaining_qty' => 2]);

            StockLedger::query()->create([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $this->makeLocation($warehouse)->id,
                'movement_type' => StockLedger::TYPE_RECEIPT, 'qty' => 10, 'unit_cost' => 8, 'total_value' => 80, 'movement_date' => '2026-01-01',
            ]);
        });

        $this->get('/inventory/valuation')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Inventory/Valuation/Index')
            ->has('rows', 2)
            ->where('summary.row_count', 2)
            ->where('summary.total_value', 118));

        $this->get("/inventory/valuation?category_id={$categoryId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.sku', 'VAL-1'));

        $this->get('/inventory/valuation?search=OTHER')->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.sku', 'VAL-OTHER'));

        $this->get("/inventory/valuation?warehouse_id={$warehouseId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 2));

        $this->get('/inventory/valuation?as_of_date=2026-01-01')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.sku', 'VAL-1')
                ->where('rows.0.total_value', 80)
                ->where('summary.as_of_date', '2026-01-01'));

        $this->get('/inventory/valuation?as_of_date=2025-01-01')->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 0));
    }
}
