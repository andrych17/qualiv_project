<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Adjustment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Services\AdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3G — Adjustment (cycle-count correction): draft CRUD plus post() (variance vs. live stock_balances). */
class AdjustmentTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    private function seedOnHandStock(int $productId, int $warehouseId, int $locationId, float $qty, float $unitCost = 4.0): void
    {
        StockBalance::query()->create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'qty_on_hand' => $qty]);
        StockValuationLayer::query()->create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'unit_cost' => $unitCost, 'qty' => $qty, 'remaining_qty' => $qty]);
    }

    public function test_admin_can_crud_a_draft_adjustment(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $productId = null;
        $reasonId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId, &$productId, &$reasonId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $locationId = $this->makeLocation($warehouse)->id;
            $productId = $this->makeProduct()->id;
            $reasonId = $this->makeAdjustmentReason()->id;
        });

        $this->get('/inventory/adjustments')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Adjustments/Index'));
        $this->get('/inventory/adjustments/create')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Adjustments/Create'));

        $this->post('/inventory/adjustments', [
            'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'adjustment_date' => now()->toDateString(),
            'reason_id' => $reasonId, 'reference' => 'CC-1',
            'lines' => [['product_id' => $productId, 'system_qty' => 5, 'counted_qty' => 8]],
        ])->assertRedirect();

        $adjustmentId = null;
        $tenant->run(function () use (&$adjustmentId) {
            $adjustment = Adjustment::query()->where('reference', 'CC-1')->first();
            $this->assertNotNull($adjustment);
            $this->assertSame(1, $adjustment->lines()->count());
            $adjustmentId = $adjustment->id;
        });

        $this->get("/inventory/adjustments/{$adjustmentId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Adjustments/Edit')->where('adjustment.reference', 'CC-1'));

        $this->put("/inventory/adjustments/{$adjustmentId}", [
            'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'adjustment_date' => now()->toDateString(),
            'reason_id' => $reasonId, 'reference' => 'CC-1 (updated)',
            'lines' => [['product_id' => $productId, 'counted_qty' => 2]],
        ])->assertRedirect();

        $tenant->run(function () use ($adjustmentId) {
            $adjustment = Adjustment::query()->find($adjustmentId);
            $this->assertSame('CC-1 (updated)', $adjustment->reference);
            $this->assertSame('2.0000', $adjustment->lines()->first()->counted_qty);
        });

        $this->delete("/inventory/adjustments/{$adjustmentId}")->assertRedirect(route('inventory.adjustments.index'));
        $tenant->run(function () use ($adjustmentId) {
            $this->assertNull(Adjustment::query()->find($adjustmentId));
        });
    }

    public function test_balance_endpoint_returns_current_on_hand_qty(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $productId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId, &$productId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $product = $this->makeProduct();
            $productId = $product->id;
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 12.5);
        });

        $this->get("/inventory/adjustments/balance?product_id={$productId}&warehouse_id={$warehouseId}&location_id={$locationId}")
            ->assertOk()->assertJson(['qty_on_hand' => 12.5]);
    }

    public function test_index_filters_by_status_warehouse_and_sorts(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $tenant->run(function () use (&$warehouseId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $reason = $this->makeAdjustmentReason();
            Adjustment::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now(), 'reason_id' => $reason->id, 'status' => Adjustment::STATUS_DRAFT]);
            Adjustment::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now(), 'reason_id' => $reason->id, 'status' => Adjustment::STATUS_POSTED]);
        });

        $this->get('/inventory/adjustments?status=posted')->assertOk()
            ->assertInertia(fn ($page) => $page->has('adjustments.data', 1)->where('adjustments.data.0.status', 'posted'));

        $this->get("/inventory/adjustments?warehouse_id={$warehouseId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('adjustments.data', 2));

        $this->get('/inventory/adjustments?sort=adjustment_date&direction=asc&per_page=5')->assertOk();
    }

    public function test_store_validation_rejects_invalid_warehouse_reason_product_and_empty_lines(): void
    {
        $this->loginAsInventoryAdmin();

        $this->post('/inventory/adjustments', ['warehouse_id' => 999999, 'location_id' => 1, 'adjustment_date' => now()->toDateString(), 'reason_id' => 999999, 'lines' => []])
            ->assertSessionHasErrors(['warehouse_id', 'reason_id', 'lines']);

        $this->post('/inventory/adjustments', [
            'warehouse_id' => 999999, 'location_id' => 1, 'adjustment_date' => now()->toDateString(), 'reason_id' => 999999,
            'lines' => [['product_id' => 999999, 'counted_qty' => 1]],
        ])->assertSessionHasErrors(['lines.0.product_id']);
    }

    public function test_update_validation_rejects_invalid_warehouse_reason_and_product(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $adjustmentId = null;
        $warehouseId = null;
        $locationId = null;
        $reasonId = null;
        $tenant->run(function () use (&$adjustmentId, &$warehouseId, &$locationId, &$reasonId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $reason = $this->makeAdjustmentReason();
            $reasonId = $reason->id;

            $adjustment = Adjustment::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now(), 'reason_id' => $reason->id, 'status' => Adjustment::STATUS_DRAFT]);
            $adjustmentId = $adjustment->id;
        });

        $this->put("/inventory/adjustments/{$adjustmentId}", [
            'warehouse_id' => 999999, 'location_id' => $locationId, 'adjustment_date' => now()->toDateString(), 'reason_id' => $reasonId,
            'lines' => [['product_id' => 999999, 'counted_qty' => 1]],
        ])->assertSessionHasErrors(['warehouse_id', 'lines.0.product_id']);

        $this->put("/inventory/adjustments/{$adjustmentId}", [
            'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'adjustment_date' => now()->toDateString(), 'reason_id' => 999999,
            'lines' => [['product_id' => 999999, 'counted_qty' => 1]],
        ])->assertSessionHasErrors(['reason_id']);
    }

    public function test_posting_applies_positive_and_negative_variance_and_skips_zero_variance(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $adjustmentId = null;
        $warehouseId = null;
        $locationId = null;
        $upProductId = null;
        $downProductId = null;
        $sameProductId = null;
        $tenant->run(function () use (&$adjustmentId, &$warehouseId, &$locationId, &$upProductId, &$downProductId, &$sameProductId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $reason = $this->makeAdjustmentReason();

            $upProduct = $this->makeProduct('ADJ-UP');
            $upProductId = $upProduct->id;
            $this->seedOnHandStock($upProduct->id, $warehouse->id, $location->id, 5, 4.0);

            $downProduct = $this->makeProduct('ADJ-DOWN');
            $downProductId = $downProduct->id;
            $this->seedOnHandStock($downProduct->id, $warehouse->id, $location->id, 5, 4.0);

            $sameProduct = $this->makeProduct('ADJ-SAME');
            $sameProductId = $sameProduct->id;
            $this->seedOnHandStock($sameProduct->id, $warehouse->id, $location->id, 5, 4.0);

            $adjustment = Adjustment::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now(), 'reason_id' => $reason->id, 'status' => Adjustment::STATUS_DRAFT]);
            $adjustment->lines()->create(['product_id' => $upProduct->id, 'system_qty' => 5, 'counted_qty' => 8]);
            $adjustment->lines()->create(['product_id' => $downProduct->id, 'system_qty' => 5, 'counted_qty' => 2]);
            $adjustment->lines()->create(['product_id' => $sameProduct->id, 'system_qty' => 5, 'counted_qty' => 5]);
            $adjustmentId = $adjustment->id;
        });

        $this->patch("/inventory/adjustments/{$adjustmentId}/post")->assertRedirect(route('inventory.adjustments.edit', $adjustmentId));

        $tenant->run(function () use ($adjustmentId, $locationId, $upProductId, $downProductId, $sameProductId) {
            $this->assertSame(Adjustment::STATUS_POSTED, Adjustment::query()->find($adjustmentId)->status);

            $upBalance = StockBalance::query()->where('product_id', $upProductId)->where('location_id', $locationId)->first();
            $this->assertSame('8.0000', $upBalance->qty_on_hand);
            $upLedger = StockLedger::query()->where('product_id', $upProductId)->where('movement_type', StockLedger::TYPE_ADJUSTMENT)->first();
            $this->assertSame('3.0000', $upLedger->qty);

            $downBalance = StockBalance::query()->where('product_id', $downProductId)->where('location_id', $locationId)->first();
            $this->assertSame('2.0000', $downBalance->qty_on_hand);
            $downLedger = StockLedger::query()->where('product_id', $downProductId)->where('movement_type', StockLedger::TYPE_ADJUSTMENT)->first();
            $this->assertSame('-3.0000', $downLedger->qty);
            $downLayer = StockValuationLayer::query()->where('product_id', $downProductId)->first();
            $this->assertSame('2.0000', $downLayer->remaining_qty);

            // Zero variance -> no ledger entry created for that line at all.
            $this->assertSame(0, StockLedger::query()->where('product_id', $sameProductId)->count());
            $sameBalance = StockBalance::query()->where('product_id', $sameProductId)->where('location_id', $locationId)->first();
            $this->assertSame('5.0000', $sameBalance->qty_on_hand);
        });

        $this->put("/inventory/adjustments/{$adjustmentId}", ['warehouse_id' => $warehouseId, 'location_id' => $locationId, 'adjustment_date' => now()->toDateString(), 'reason_id' => 1, 'lines' => [['product_id' => $upProductId, 'counted_qty' => 1]]])
            ->assertSessionHasErrors(['status']);
        $this->delete("/inventory/adjustments/{$adjustmentId}")->assertSessionHasErrors(['status']);
        $this->patch("/inventory/adjustments/{$adjustmentId}/post")->assertSessionHasErrors(['status']);
    }

    public function test_posting_is_blocked_with_no_lines_or_a_foreign_location(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $emptyId = null;
        $foreignId = null;
        $tenant->run(function () use (&$emptyId, &$foreignId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $otherWarehouse = $this->makeWarehouse('Other WH');
            $foreignLocation = $this->makeLocation($otherWarehouse, 'FOREIGN');
            $reason = $this->makeAdjustmentReason();
            $product = $this->makeProduct('ADJ-FOREIGN');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5);

            $emptyId = Adjustment::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now(), 'reason_id' => $reason->id, 'status' => Adjustment::STATUS_DRAFT])->id;

            $foreign = Adjustment::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $foreignLocation->id, 'adjustment_date' => now(), 'reason_id' => $reason->id, 'status' => Adjustment::STATUS_DRAFT]);
            $foreign->lines()->create(['product_id' => $product->id, 'counted_qty' => 9]);
            $foreignId = $foreign->id;
        });

        $this->patch("/inventory/adjustments/{$emptyId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/adjustments/{$foreignId}/post")->assertSessionHasErrors(['location_id']);
    }

    public function test_posting_is_blocked_for_inactive_product_batch_without_lot_and_serial_tracking(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $inactiveId = null;
        $noLotId = null;
        $serialId = null;
        $tenant->run(function () use (&$inactiveId, &$noLotId, &$serialId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $reason = $this->makeAdjustmentReason();

            $inactiveProduct = $this->makeProduct('ADJ-INACTIVE');
            $this->seedOnHandStock($inactiveProduct->id, $warehouse->id, $location->id, 5);
            $inactiveProduct->update(['is_active' => false]);
            $inactiveAdj = Adjustment::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now(), 'reason_id' => $reason->id, 'status' => Adjustment::STATUS_DRAFT]);
            $inactiveAdj->lines()->create(['product_id' => $inactiveProduct->id, 'counted_qty' => 1]);
            $inactiveId = $inactiveAdj->id;

            $batchProduct = $this->makeProduct('ADJ-BATCH', ['tracking_mode' => Product::TRACKING_BATCH]);
            $this->seedOnHandStock($batchProduct->id, $warehouse->id, $location->id, 5);
            $noLotAdj = Adjustment::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now(), 'reason_id' => $reason->id, 'status' => Adjustment::STATUS_DRAFT]);
            $noLotAdj->lines()->create(['product_id' => $batchProduct->id, 'counted_qty' => 9, 'batch_id' => null]);
            $noLotId = $noLotAdj->id;

            $serialProduct = $this->makeProduct('ADJ-SERIAL', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $this->seedOnHandStock($serialProduct->id, $warehouse->id, $location->id, 5);
            $serialAdj = Adjustment::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now(), 'reason_id' => $reason->id, 'status' => Adjustment::STATUS_DRAFT]);
            $serialAdj->lines()->create(['product_id' => $serialProduct->id, 'counted_qty' => 9]);
            $serialId = $serialAdj->id;
        });

        $this->patch("/inventory/adjustments/{$inactiveId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/adjustments/{$noLotId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/adjustments/{$serialId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_updating_an_adjustment_silently_drops_a_blank_line(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct();
            $reason = $this->makeAdjustmentReason();
            $adjustment = Adjustment::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now(), 'reason_id' => $reason->id, 'status' => Adjustment::STATUS_DRAFT]);

            app(AdjustmentService::class)->update($adjustment, [
                'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now()->toDateString(), 'reason_id' => $reason->id,
                'lines' => [
                    ['product_id' => $product->id, 'counted_qty' => 5],
                    ['product_id' => null, 'counted_qty' => null],
                ],
            ]);

            $this->assertSame(1, $adjustment->lines()->count());
        });
    }
}
