<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Adjustment;
use App\Modules\Inventory\Models\AdjustmentReason;
use App\Modules\Inventory\Models\CycleCount;
use App\Modules\Inventory\Models\CycleCountLine;
use App\Modules\Inventory\Models\GoodsReceiptLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PutawayRule;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\CycleCountService;
use App\Modules\Inventory\Services\PutawayRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3Q/§3R — Cycle Counting (scoped count -> scan-to-count -> draft Adjustment) and Put-away Rules (Goods Receipt default location). */
class CycleCountPutawayTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    public function test_cycle_count_by_location_scope_counts_variances_and_drafts_an_adjustment(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $upProductId = null;
        $downProductId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId, &$upProductId, &$downProductId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;

            $upProduct = $this->makeProduct('CC-UP');
            $upProductId = $upProduct->id;
            StockBalance::query()->create(['product_id' => $upProduct->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 5]);

            $downProduct = $this->makeProduct('CC-DOWN');
            $downProductId = $downProduct->id;
            StockBalance::query()->create(['product_id' => $downProduct->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 5]);
        });

        $this->get('/inventory/cycle-counts')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/CycleCounts/Index'));
        $this->get('/inventory/cycle-counts/create')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/CycleCounts/Create'));

        $this->post('/inventory/cycle-counts', [
            'warehouse_id' => $warehouseId, 'location_id' => $locationId,
        ])->assertRedirect();

        $countId = null;
        $upLineId = null;
        $downLineId = null;
        $tenant->run(function () use (&$countId, &$upLineId, &$downLineId, $upProductId, $downProductId) {
            $count = CycleCount::query()->first();
            $this->assertNotNull($count);
            $this->assertSame(2, $count->lines()->count());
            $countId = $count->id;
            $upLineId = CycleCountLine::query()->where('cycle_count_id', $countId)->where('product_id', $upProductId)->value('id');
            $downLineId = CycleCountLine::query()->where('cycle_count_id', $countId)->where('product_id', $downProductId)->value('id');
        });

        $this->get("/inventory/cycle-counts/{$countId}")->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/CycleCounts/Show')->has('lines', 2));

        $this->patch("/inventory/cycle-counts/{$countId}/assign", ['assigned_to' => null])->assertRedirect();

        $this->patch("/inventory/cycle-counts/{$countId}/lines/{$upLineId}/count", ['counted_qty' => 8])->assertRedirect();
        $tenant->run(function () use ($countId) {
            $this->assertSame(CycleCount::STATUS_IN_PROGRESS, CycleCount::query()->find($countId)->status);
        });

        // Complete before every line counted -> blocked.
        $this->patch("/inventory/cycle-counts/{$countId}/complete")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/cycle-counts/{$countId}/lines/{$upLineId}/count", ['counted_qty' => 1])->assertSessionHasErrors(['status']);

        $this->patch("/inventory/cycle-counts/{$countId}/lines/{$downLineId}/count", ['counted_qty' => 2])->assertRedirect();

        $this->patch("/inventory/cycle-counts/{$countId}/complete")->assertRedirect(route('inventory.cycleCounts.show', $countId));

        $tenant->run(function () use ($countId, $upProductId, $downProductId) {
            $count = CycleCount::query()->find($countId);
            $this->assertSame(CycleCount::STATUS_COMPLETED, $count->status);
            $this->assertNotNull($count->completed_at);

            $adjustment = Adjustment::query()->where('reference', "Cycle Count #{$countId}")->first();
            $this->assertNotNull($adjustment);
            $this->assertSame(Adjustment::STATUS_DRAFT, $adjustment->status);
            $this->assertSame(2, $adjustment->lines()->count());

            $upLine = $adjustment->lines()->where('product_id', $upProductId)->first();
            $this->assertSame('8.0000', $upLine->counted_qty);
            $downLine = $adjustment->lines()->where('product_id', $downProductId)->first();
            $this->assertSame('2.0000', $downLine->counted_qty);
        });

        $this->patch("/inventory/cycle-counts/{$countId}/complete")->assertSessionHasErrors(['status']);
        $this->delete("/inventory/cycle-counts/{$countId}")->assertSessionHasErrors(['status']);
    }

    public function test_cycle_count_by_category_and_abc_class_scope_and_delete_guard(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $categoryId = null;
        $tenant->run(function () use (&$warehouseId, &$categoryId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $category = $this->makeCategory('CC-Category');
            $categoryId = $category->id;

            $catProduct = $this->makeProduct('CC-CAT', ['category_id' => $category->id]);
            StockBalance::query()->create(['product_id' => $catProduct->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 3]);

            $abcProduct = $this->makeProduct('CC-ABC', ['abc_class' => Product::ABC_A]);
            StockBalance::query()->create(['product_id' => $abcProduct->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 3]);
        });

        $this->post('/inventory/cycle-counts', ['warehouse_id' => $warehouseId, 'category_id' => $categoryId])->assertRedirect();
        $catCountId = null;
        $tenant->run(function () use (&$catCountId) {
            $count = CycleCount::query()->where('category_id', '!=', null)->first();
            $this->assertSame(1, $count->lines()->count());
            $catCountId = $count->id;
        });

        $this->post('/inventory/cycle-counts', ['warehouse_id' => $warehouseId, 'abc_class' => Product::ABC_A])->assertRedirect();
        $abcCountId = null;
        $tenant->run(function () use (&$abcCountId) {
            $count = CycleCount::query()->where('abc_class', Product::ABC_A)->first();
            $this->assertSame(1, $count->lines()->count());
            $abcCountId = $count->id;
        });

        // Untouched (no counted lines yet) -> deletes cleanly.
        $this->delete("/inventory/cycle-counts/{$abcCountId}")->assertRedirect(route('inventory.cycleCounts.index'));
        $tenant->run(function () use ($abcCountId) {
            $this->assertNull(CycleCount::query()->find($abcCountId));
        });

        // No scope at all -> nothing to count.
        $emptyWarehouseId = null;
        $tenant->run(function () use (&$emptyWarehouseId) {
            $emptyWarehouseId = $this->makeWarehouse('Empty WH')->id;
        });
        $this->post('/inventory/cycle-counts', ['warehouse_id' => $emptyWarehouseId, 'category_id' => $categoryId])->assertSessionHasErrors(['lines']);

        // Delete guard: once a line has been counted, the count can no longer be scrapped.
        $lineId = null;
        $tenant->run(function () use (&$lineId, $catCountId) {
            $lineId = CycleCountLine::query()->where('cycle_count_id', $catCountId)->value('id');
        });
        $this->patch("/inventory/cycle-counts/{$catCountId}/lines/{$lineId}/count", ['counted_qty' => 0])->assertRedirect();
        $this->delete("/inventory/cycle-counts/{$catCountId}")->assertSessionHasErrors(['status']);
    }

    public function test_store_cycle_count_validation_rejects_zero_or_multiple_scopes_and_invalid_refs(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $categoryId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId, &$categoryId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $locationId = $this->makeLocation($warehouse)->id;
            $categoryId = $this->makeCategory()->id;
        });

        $this->post('/inventory/cycle-counts', ['warehouse_id' => $warehouseId])->assertSessionHasErrors(['location_id']);

        $this->post('/inventory/cycle-counts', ['warehouse_id' => $warehouseId, 'location_id' => $locationId, 'category_id' => $categoryId])
            ->assertSessionHasErrors(['location_id']);

        $this->post('/inventory/cycle-counts', ['warehouse_id' => 999999, 'location_id' => $locationId])->assertSessionHasErrors(['warehouse_id']);
        $this->post('/inventory/cycle-counts', ['warehouse_id' => $warehouseId, 'location_id' => 999999])->assertSessionHasErrors(['location_id']);
        $this->post('/inventory/cycle-counts', ['warehouse_id' => $warehouseId, 'category_id' => 999999])->assertSessionHasErrors(['category_id']);
    }

    public function test_count_line_validation_rejects_recount_and_negative_qty(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $product = $this->makeProduct('CC-NEG');
            StockBalance::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 3]);
        });

        $this->post('/inventory/cycle-counts', ['warehouse_id' => $warehouseId, 'location_id' => $locationId])->assertRedirect();

        $countId = null;
        $lineId = null;
        $tenant->run(function () use (&$countId, &$lineId) {
            $count = CycleCount::query()->first();
            $countId = $count->id;
            $lineId = $count->lines()->first()->id;
        });

        // The controller's own inline validate() (min:0) already blocks a negative value before
        // it ever reaches CycleCountService::countLine() — its own defensive check is only
        // reachable via a direct call.
        $tenant->run(function () use ($lineId) {
            try {
                app(CycleCountService::class)->countLine(CycleCountLine::query()->find($lineId), -1);
                $this->fail('Expected a ValidationException for a negative counted_qty.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('counted_qty', $e->errors());
            }
        });

        $this->patch("/inventory/cycle-counts/{$countId}/lines/{$lineId}/count", ['counted_qty' => -1])->assertSessionHasErrors(['counted_qty']);
        $this->patch("/inventory/cycle-counts/{$countId}/lines/{$lineId}/count", ['counted_qty' => 3])->assertRedirect();
        $this->patch("/inventory/cycle-counts/{$countId}/lines/{$lineId}/count", ['counted_qty' => 3])->assertSessionHasErrors(['status']);
    }

    public function test_admin_can_crud_a_putaway_rule_and_it_drives_the_goods_receipt_default_location(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $productId = null;
        $uomId = null;
        $targetLocationId = null;
        $tenant->run(function () use (&$warehouseId, &$productId, &$uomId, &$targetLocationId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $targetLocationId = $this->makeLocation($warehouse, 'PUTAWAY-TARGET')->id;
            $product = $this->makeProduct('PUTAWAY-PROD');
            $productId = $product->id;
            $uomId = $product->base_uom_id;
        });

        $this->get('/inventory/putaway-rules')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/PutawayRules/Index'));
        $this->get('/inventory/putaway-rules/create')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/PutawayRules/Create'));

        $this->post('/inventory/putaway-rules', [
            'warehouse_id' => $warehouseId, 'product_id' => $productId, 'target_location_id' => $targetLocationId, 'priority_order' => 1,
        ])->assertRedirect(route('inventory.putawayRules.index'));

        $ruleId = null;
        $tenant->run(function () use (&$ruleId, $productId) {
            $rule = PutawayRule::query()->where('product_id', $productId)->first();
            $this->assertNotNull($rule);
            $ruleId = $rule->id;
        });

        $this->get("/inventory/putaway-rules/{$ruleId}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/PutawayRules/Edit'));
        $this->put("/inventory/putaway-rules/{$ruleId}", [
            'warehouse_id' => $warehouseId, 'product_id' => $productId, 'target_location_id' => $targetLocationId, 'priority_order' => 5, 'is_active' => true,
        ])->assertRedirect(route('inventory.putawayRules.index'));
        $tenant->run(function () use ($ruleId) {
            $this->assertSame(5, PutawayRule::query()->find($ruleId)->priority_order);
        });

        // Drives the default destination on a Goods Receipt line that omits one.
        $this->post('/inventory/goods-receipts', [
            'warehouse_id' => $warehouseId, 'receipt_date' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'qty' => 5, 'uom_id' => $uomId, 'unit_cost' => 1]],
        ])->assertRedirect();
        $tenant->run(function () use ($productId, $targetLocationId) {
            $line = GoodsReceiptLine::query()->where('product_id', $productId)->first();
            $this->assertSame($targetLocationId, $line->destination_location_id);
        });

        $ids = [];
        $tenant->run(function () use (&$ids, $warehouseId, $targetLocationId) {
            $ids[] = PutawayRule::query()->create(['warehouse_id' => $warehouseId, 'category_id' => $this->makeCategory('Bulk Cat')->id, 'target_location_id' => $targetLocationId])->id;
            $ids[] = PutawayRule::query()->create(['warehouse_id' => $warehouseId, 'category_id' => $this->makeCategory('Bulk Cat 2')->id, 'target_location_id' => $targetLocationId])->id;
        });
        $this->delete('/inventory/putaway-rules/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, PutawayRule::query()->whereIn('id', $ids)->count());
        });

        $this->delete("/inventory/putaway-rules/{$ruleId}")->assertRedirect(route('inventory.putawayRules.index'));
        $tenant->run(function () use ($ruleId) {
            $this->assertNull(PutawayRule::query()->find($ruleId));
        });
    }

    public function test_putaway_rule_index_filters_and_store_validation(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $locationId = $this->makeLocation($warehouse)->id;
            PutawayRule::query()->create(['warehouse_id' => $warehouse->id, 'category_id' => $this->makeCategory('Filter Cat')->id, 'target_location_id' => $locationId, 'is_active' => true]);
            PutawayRule::query()->create(['warehouse_id' => $warehouse->id, 'category_id' => $this->makeCategory('Filter Cat 2')->id, 'target_location_id' => $locationId, 'is_active' => false]);
        });

        $this->get("/inventory/putaway-rules?warehouse_id={$warehouseId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('rules.data', 2));

        $this->get('/inventory/putaway-rules?status=inactive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('rules.data', 1));

        $this->get('/inventory/putaway-rules?sort=priority_order&direction=asc&per_page=5')->assertOk();

        // Neither product_id nor category_id -> rejected.
        $this->post('/inventory/putaway-rules', ['warehouse_id' => $warehouseId, 'target_location_id' => $locationId])
            ->assertSessionHasErrors(['product_id']);

        // Both at once -> also rejected.
        $productId = null;
        $categoryId = null;
        $tenant->run(function () use (&$productId, &$categoryId) {
            $productId = $this->makeProduct()->id;
            $categoryId = $this->makeCategory('Both Cat')->id;
        });
        $this->post('/inventory/putaway-rules', [
            'warehouse_id' => $warehouseId, 'product_id' => $productId, 'category_id' => $categoryId, 'target_location_id' => $locationId,
        ])->assertSessionHasErrors(['product_id']);

        $this->post('/inventory/putaway-rules', ['warehouse_id' => 999999, 'product_id' => $productId, 'target_location_id' => $locationId])
            ->assertSessionHasErrors(['warehouse_id']);
        $this->post('/inventory/putaway-rules', ['warehouse_id' => $warehouseId, 'category_id' => 999999, 'target_location_id' => $locationId])
            ->assertSessionHasErrors(['category_id']);

        // Target location doesn't belong to the selected warehouse.
        $foreignLocationId = null;
        $tenant->run(function () use (&$foreignLocationId) {
            $foreignLocationId = $this->makeLocation($this->makeWarehouse('Foreign WH'), 'FOREIGN')->id;
        });
        $this->post('/inventory/putaway-rules', [
            'warehouse_id' => $warehouseId, 'product_id' => $productId, 'target_location_id' => $foreignLocationId,
        ])->assertSessionHasErrors(['target_location_id']);

        // The same four checks on UPDATE — a distinct withValidator() in UpdatePutawayRuleRequest.
        $ruleId = null;
        $tenant->run(function () use (&$ruleId, $warehouseId, $locationId, $productId) {
            $ruleId = PutawayRule::query()->create(['warehouse_id' => $warehouseId, 'product_id' => $productId, 'target_location_id' => $locationId])->id;
        });

        $this->put("/inventory/putaway-rules/{$ruleId}", ['warehouse_id' => $warehouseId, 'target_location_id' => $locationId])
            ->assertSessionHasErrors(['product_id']);
        $this->put("/inventory/putaway-rules/{$ruleId}", [
            'warehouse_id' => $warehouseId, 'product_id' => $productId, 'category_id' => $categoryId, 'target_location_id' => $locationId,
        ])->assertSessionHasErrors(['product_id']);
        $this->put("/inventory/putaway-rules/{$ruleId}", ['warehouse_id' => 999999, 'product_id' => $productId, 'target_location_id' => $locationId])
            ->assertSessionHasErrors(['warehouse_id']);
        $this->put("/inventory/putaway-rules/{$ruleId}", ['warehouse_id' => $warehouseId, 'category_id' => 999999, 'target_location_id' => $locationId])
            ->assertSessionHasErrors(['category_id']);
        $this->put("/inventory/putaway-rules/{$ruleId}", [
            'warehouse_id' => $warehouseId, 'product_id' => $productId, 'target_location_id' => $foreignLocationId,
        ])->assertSessionHasErrors(['target_location_id']);
    }

    public function test_putaway_rule_resolve_matches_by_category_falls_through_to_no_match_and_ignores_an_unknown_product(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $service = app(PutawayRuleService::class);

            // An unknown product_id -> resolve() returns null without even querying rules.
            $this->assertNull($service->resolve(999999, $this->makeWarehouse()->id));

            $warehouse = $this->makeWarehouse();
            $categoryTarget = $this->makeLocation($warehouse, 'CAT-TARGET');
            $category = $this->makeCategory('Resolve Cat');
            $product = $this->makeProduct('RESOLVE-CAT-PROD', ['category_id' => $category->id]);

            // No rule at all yet -> falls through the loop to the final null.
            $this->assertNull($service->resolve($product->id, $warehouse->id));

            $service->create(['warehouse_id' => $warehouse->id, 'category_id' => $category->id, 'target_location_id' => $categoryTarget->id]);
            $this->assertSame($categoryTarget->id, $service->resolve($product->id, $warehouse->id));
        });
    }

    public function test_cycle_count_complete_is_blocked_when_the_count_variance_reason_is_not_configured(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $countId = null;
        $tenant->run(function () use (&$countId) {
            // The migration seeds a 'count_variance' reason by default — remove it to force the guard.
            AdjustmentReason::query()->where('code', 'count_variance')->delete();

            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('CC-NOREASON');
            StockBalance::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 1]);

            $count = app(CycleCountService::class)->create(['warehouse_id' => $warehouse->id, 'location_id' => $location->id]);
            app(CycleCountService::class)->countLine($count->lines->first(), 1);
            $countId = $count->id;
        });

        $this->patch("/inventory/cycle-counts/{$countId}/complete")->assertSessionHasErrors(['status']);
    }
}
