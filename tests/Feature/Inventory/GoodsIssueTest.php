<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\GoodsIssue;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Models\UomConversion;
use App\Modules\Inventory\Services\GoodsIssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3E — Goods Issue: draft CRUD plus post() (consumes valuation layers, the only ledger-touching action). */
class GoodsIssueTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    /** Seeds a $qty-unit open FIFO layer + matching stock_balances row so post() has stock to consume. */
    private function seedOnHandStock(int $productId, int $warehouseId, int $locationId, float $qty, float $unitCost = 4.0, ?int $batchId = null): void
    {
        StockBalance::query()->create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'batch_id' => $batchId, 'qty_on_hand' => $qty]);
        StockValuationLayer::query()->create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'batch_id' => $batchId, 'unit_cost' => $unitCost, 'qty' => $qty, 'remaining_qty' => $qty]);
    }

    public function test_admin_can_crud_a_draft_goods_issue(): void
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

        $this->get('/inventory/goods-issues')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/GoodsIssues/Index'));
        $this->get('/inventory/goods-issues/create')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/GoodsIssues/Create'));

        $this->post('/inventory/goods-issues', [
            'warehouse_id' => $warehouseId, 'issue_date' => now()->toDateString(), 'reason' => GoodsIssue::REASON_CONSUMPTION,
            'lines' => [['product_id' => $productId, 'qty' => 3, 'uom_id' => $uomId, 'source_location_id' => $locationId]],
        ])->assertRedirect();

        $issueId = null;
        $tenant->run(function () use (&$issueId) {
            $issue = GoodsIssue::query()->where('reason', GoodsIssue::REASON_CONSUMPTION)->first();
            $this->assertNotNull($issue);
            $this->assertSame(1, $issue->lines()->count());
            $issueId = $issue->id;
        });

        $this->get("/inventory/goods-issues/{$issueId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/GoodsIssues/Edit')->where('issue.reason', GoodsIssue::REASON_CONSUMPTION));

        $this->put("/inventory/goods-issues/{$issueId}", [
            'warehouse_id' => $warehouseId, 'issue_date' => now()->toDateString(), 'reason' => GoodsIssue::REASON_SAMPLE,
            'lines' => [['product_id' => $productId, 'qty' => 5, 'uom_id' => $uomId, 'source_location_id' => $locationId]],
        ])->assertRedirect();

        $tenant->run(function () use ($issueId) {
            $issue = GoodsIssue::query()->find($issueId);
            $this->assertSame(GoodsIssue::REASON_SAMPLE, $issue->reason);
            $this->assertSame('5.0000', $issue->lines()->first()->qty);
        });

        $this->delete("/inventory/goods-issues/{$issueId}")->assertRedirect(route('inventory.goodsIssues.index'));
        $tenant->run(function () use ($issueId) {
            $this->assertNull(GoodsIssue::query()->find($issueId));
        });
    }

    public function test_index_filters_by_status_warehouse_and_sorts(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $tenant->run(function () use (&$warehouseId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_POSTED]);
        });

        $this->get('/inventory/goods-issues?status=posted')->assertOk()
            ->assertInertia(fn ($page) => $page->has('issues.data', 1)->where('issues.data.0.status', 'posted'));

        $this->get("/inventory/goods-issues?warehouse_id={$warehouseId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('issues.data', 2));

        $this->get('/inventory/goods-issues?sort=issue_date&direction=asc&per_page=5')->assertOk()->assertOk();
    }

    public function test_store_validation_rejects_invalid_warehouse_product_uom_and_empty_lines(): void
    {
        $this->loginAsInventoryAdmin();

        $this->post('/inventory/goods-issues', ['warehouse_id' => 999999, 'issue_date' => now()->toDateString(), 'lines' => []])
            ->assertSessionHasErrors(['warehouse_id', 'lines']);

        $this->post('/inventory/goods-issues', [
            'warehouse_id' => 999999, 'issue_date' => now()->toDateString(),
            'lines' => [['product_id' => 999999, 'qty' => 1, 'uom_id' => 999999]],
        ])->assertSessionHasErrors(['lines.0.product_id', 'lines.0.uom_id']);
    }

    public function test_update_validation_rejects_invalid_warehouse_product_and_uom(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $issueId = null;
        $tenant->run(function () use (&$issueId) {
            $warehouse = $this->makeWarehouse();
            $issueId = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT])->id;
        });

        $this->put("/inventory/goods-issues/{$issueId}", ['warehouse_id' => 999999, 'issue_date' => now()->toDateString(), 'lines' => []])
            ->assertSessionHasErrors(['warehouse_id', 'lines']);

        $this->put("/inventory/goods-issues/{$issueId}", [
            'warehouse_id' => 999999, 'issue_date' => now()->toDateString(),
            'lines' => [['product_id' => 999999, 'qty' => 1, 'uom_id' => 999999]],
        ])->assertSessionHasErrors(['lines.0.product_id', 'lines.0.uom_id']);
    }

    public function test_posting_an_issue_consumes_the_valuation_layer_and_reduces_balance(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $issueId = null;
        $warehouseId = null;
        $locationId = null;
        $productId = null;
        $tenant->run(function () use (&$issueId, &$warehouseId, &$locationId, &$productId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $product = $this->makeProduct('ISS-1', ['costing_method' => Product::COSTING_FIFO]);
            $productId = $product->id;
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 10);

            $issue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $issue->lines()->create(['product_id' => $product->id, 'qty' => 4, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id]);
            $issueId = $issue->id;
        });

        $this->patch("/inventory/goods-issues/{$issueId}/post")->assertRedirect(route('inventory.goodsIssues.edit', $issueId));

        $tenant->run(function () use ($issueId, $warehouseId, $locationId, $productId) {
            $issue = GoodsIssue::query()->find($issueId);
            $this->assertSame(GoodsIssue::STATUS_POSTED, $issue->status);

            $ledger = StockLedger::query()->where('subject_type', 'inventory.goods_issues')->where('subject_id', (string) $issueId)->first();
            $this->assertNotNull($ledger);
            $this->assertSame(StockLedger::TYPE_ISSUE, $ledger->movement_type);
            $this->assertSame('-4.0000', $ledger->qty);

            $layer = StockValuationLayer::query()->where('product_id', $productId)->first();
            $this->assertSame('6.0000', $layer->remaining_qty);

            $balance = StockBalance::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('location_id', $locationId)->first();
            $this->assertSame('6.0000', $balance->qty_on_hand);
        });

        $this->put("/inventory/goods-issues/{$issueId}", ['warehouse_id' => $warehouseId, 'issue_date' => now()->toDateString(), 'lines' => [['product_id' => $productId, 'qty' => 1, 'uom_id' => 1]]])
            ->assertSessionHasErrors(['status']);
        $this->delete("/inventory/goods-issues/{$issueId}")->assertSessionHasErrors(['status']);
        $this->patch("/inventory/goods-issues/{$issueId}/post")->assertSessionHasErrors(['status']);
    }

    public function test_posting_is_blocked_when_over_issuing_or_missing_or_foreign_source_location(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $overIssueId = null;
        $noSourceId = null;
        $foreignSourceId = null;
        $tenant->run(function () use (&$overIssueId, &$noSourceId, &$foreignSourceId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $otherWarehouse = $this->makeWarehouse('Other WH');
            $foreignLocation = $this->makeLocation($otherWarehouse, 'FOREIGN');
            $product = $this->makeProduct('OVER-1');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 2);

            $overIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $overIssue->lines()->create(['product_id' => $product->id, 'qty' => 100, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id]);
            $overIssueId = $overIssue->id;

            $noSourceIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $noSourceIssue->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => null]);
            $noSourceId = $noSourceIssue->id;

            $foreignSourceIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $foreignSourceIssue->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => $foreignLocation->id]);
            $foreignSourceId = $foreignSourceIssue->id;
        });

        $this->patch("/inventory/goods-issues/{$overIssueId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/goods-issues/{$noSourceId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/goods-issues/{$foreignSourceId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_posting_is_blocked_for_inactive_product_and_empty_lines(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $emptyId = null;
        $inactiveId = null;
        $tenant->run(function () use (&$emptyId, &$inactiveId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);

            $emptyId = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT])->id;

            $inactiveProduct = $this->makeProduct('INACTIVE-ISS');
            $this->seedOnHandStock($inactiveProduct->id, $warehouse->id, $location->id, 5);
            $inactiveProduct->update(['is_active' => false]);
            $inactiveIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $inactiveIssue->lines()->create(['product_id' => $inactiveProduct->id, 'qty' => 1, 'uom_id' => $inactiveProduct->base_uom_id, 'source_location_id' => $location->id]);
            $inactiveId = $inactiveIssue->id;
        });

        $this->patch("/inventory/goods-issues/{$emptyId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/goods-issues/{$inactiveId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_posting_a_batch_tracked_line_requires_a_batch_and_blocks_an_expired_lot_without_override(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $noBatchId = null;
        $expiredId = null;
        $overriddenId = null;
        $noExpiryId = null;
        $tenant->run(function () use (&$noBatchId, &$expiredId, &$overriddenId, &$noExpiryId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('BATCH-ISS', ['tracking_mode' => Product::TRACKING_BATCH]);

            $noBatchIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $noBatchIssue->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id, 'batch_id' => null]);
            $noBatchId = $noBatchIssue->id;

            $expiredBatch = StockBatch::query()->create(['product_id' => $product->id, 'batch_number' => 'EXP-1', 'expiry_date' => now()->subDay()->toDateString()]);
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5, 4.0, $expiredBatch->id);

            $expiredIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $expiredIssue->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id, 'batch_id' => $expiredBatch->id]);
            $expiredId = $expiredIssue->id;

            $overriddenIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $overriddenIssue->lines()->create([
                'product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id,
                'batch_id' => $expiredBatch->id, 'expiry_override_reason' => 'Client accepted the risk.',
            ]);
            $overriddenId = $overriddenIssue->id;

            // A lot with no expiry date at all (isExpiredAsOf()'s null-date branch) never blocks.
            $noExpiryBatch = StockBatch::query()->create(['product_id' => $product->id, 'batch_number' => 'NO-EXPIRY-1']);
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5, 4.0, $noExpiryBatch->id);
            $noExpiryIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $noExpiryIssue->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id, 'batch_id' => $noExpiryBatch->id]);
            $noExpiryId = $noExpiryIssue->id;
        });

        $this->patch("/inventory/goods-issues/{$noBatchId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/goods-issues/{$expiredId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/goods-issues/{$overriddenId}/post")->assertRedirect();
        $this->patch("/inventory/goods-issues/{$noExpiryId}/post")->assertRedirect();
    }

    public function test_posting_a_serial_tracked_line_requires_matching_serial_count_and_flips_status(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $mismatchId = null;
        $okId = null;
        $productId = null;
        $serialNumber = 'SN-ISSUE-1';
        $tenant->run(function () use (&$mismatchId, &$okId, &$productId, $serialNumber) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('SERIAL-ISS', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $productId = $product->id;
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 1);
            StockSerial::query()->create([
                'product_id' => $product->id, 'serial_number' => $serialNumber, 'status' => StockSerial::STATUS_IN_STOCK,
                'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            ]);

            $mismatchIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $mismatchIssue->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id, 'serial_numbers' => []]);
            $mismatchId = $mismatchIssue->id;

            $okIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $okIssue->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id, 'serial_numbers' => [$serialNumber]]);
            $okId = $okIssue->id;
        });

        $this->patch("/inventory/goods-issues/{$mismatchId}/post")->assertSessionHasErrors(['lines']);

        $this->patch("/inventory/goods-issues/{$okId}/post")->assertRedirect();
        $tenant->run(function () use ($productId, $serialNumber) {
            $serial = StockSerial::query()->where('product_id', $productId)->where('serial_number', $serialNumber)->first();
            $this->assertSame(StockSerial::STATUS_ISSUED, $serial->status);
            $this->assertNull($serial->location_id);
        });
    }

    public function test_posting_is_blocked_for_a_serial_thats_not_in_stock_at_the_wrong_location_or_unknown(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $notInStockId = null;
        $wrongLocationId = null;
        $unknownId = null;
        $tenant->run(function () use (&$notInStockId, &$wrongLocationId, &$unknownId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $elsewhere = $this->makeLocation($warehouse, 'ELSEWHERE');
            $product = $this->makeProduct('SERIAL-EDGE', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 3);

            StockSerial::query()->create(['product_id' => $product->id, 'serial_number' => 'SN-ALREADY-ISSUED', 'status' => StockSerial::STATUS_ISSUED, 'warehouse_id' => null, 'location_id' => null]);
            $notInStockIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $notInStockIssue->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id, 'serial_numbers' => ['SN-ALREADY-ISSUED']]);
            $notInStockId = $notInStockIssue->id;

            StockSerial::query()->create(['product_id' => $product->id, 'serial_number' => 'SN-ELSEWHERE', 'status' => StockSerial::STATUS_IN_STOCK, 'warehouse_id' => $warehouse->id, 'location_id' => $elsewhere->id]);
            $wrongLocationIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $wrongLocationIssue->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id, 'serial_numbers' => ['SN-ELSEWHERE']]);
            $wrongLocationId = $wrongLocationIssue->id;

            $unknownIssue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $unknownIssue->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id, 'serial_numbers' => ['SN-NEVER-EXISTED']]);
            $unknownId = $unknownIssue->id;
        });

        $this->patch("/inventory/goods-issues/{$notInStockId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/goods-issues/{$wrongLocationId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/goods-issues/{$unknownId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_posting_rejects_a_fractional_qty_on_a_serial_tracked_line(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $issueId = null;
        $tenant->run(function () use (&$issueId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('ISS-SERIAL-FRACTIONAL', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 3);
            $looseUom = $this->makeUom('LOOSE-ISS', 'Loose Pack');
            UomConversion::query()->create(['product_id' => $product->id, 'uom_id' => $looseUom->id, 'conversion_factor' => 1.5]);

            $issue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $issue->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $looseUom->id, 'source_location_id' => $location->id, 'serial_numbers' => ['SN-ISS-FRAC-1']]);
            $issueId = $issue->id;
        });

        $this->patch("/inventory/goods-issues/{$issueId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_updating_an_issue_silently_drops_a_blank_line(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct();
            $issue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);

            app(GoodsIssueService::class)->update($issue, [
                'warehouse_id' => $warehouse->id, 'issue_date' => now()->toDateString(),
                'lines' => [
                    ['product_id' => $product->id, 'qty' => 2, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id],
                    ['product_id' => null, 'qty' => null, 'uom_id' => $product->base_uom_id],
                ],
            ]);

            $this->assertSame(1, $issue->lines()->count());
        });
    }
}
