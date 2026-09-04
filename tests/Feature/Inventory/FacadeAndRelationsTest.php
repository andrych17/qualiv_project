<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Accounting\Events\InventoryGoodsIssued;
use App\Modules\Accounting\Events\InventoryGoodsReceived;
use App\Modules\Accounting\Events\InventoryStockAdjusted;
use App\Modules\Accounting\Models\Company;
use App\Modules\Inventory\Models\Adjustment;
use App\Modules\Inventory\Models\CycleCount;
use App\Modules\Inventory\Models\CycleCountLine;
use App\Modules\Inventory\Models\GoodsIssue;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryCategory;
use App\Modules\Inventory\Models\InventoryItem;
use App\Modules\Inventory\Models\PackList;
use App\Modules\Inventory\Models\PackListLine;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Inventory\Models\UomConversion;
use App\Modules\Inventory\Services\AccountingCompanyResolver;
use App\Modules\Inventory\Services\AdjustmentService;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockBalanceRebuildService;
use App\Services\TenantFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Legacy Inventory Items CRUD (public-schema demo tables, unrelated to the INVENTORY.* engine), the InventoryService cross-module facade, AccountingCompanyResolver's GL-posting gate, and the stock-balance rebuild safety net. */
class FacadeAndRelationsTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    public function test_admin_can_crud_a_legacy_inventory_item_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $categoryId = null;
        $tenant->run(function () use (&$categoryId) {
            $categoryId = InventoryCategory::query()->create(['code' => 'CAT-1', 'name' => 'Category One', 'status' => 'active'])->id;
        });

        $this->get('/inventory/items')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Items/Index'));
        $this->get('/inventory/items/create')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Items/Create'));

        $this->post('/inventory/items', [
            'code' => 'ITEM-1', 'name' => 'Widget', 'inventory_category_id' => $categoryId,
            'stock' => 10, 'minimum_stock' => 2, 'unit' => 'pcs', 'status' => 'active',
        ])->assertRedirect(route('inventory.items.index'));

        $itemId = null;
        $tenant->run(function () use (&$itemId) {
            $item = InventoryItem::query()->where('code', 'ITEM-1')->first();
            $this->assertNotNull($item);
            $itemId = $item->id;
        });

        // A show route was never meant to exist for this legacy resource (no Show.vue) — GET isn't
        // registered at this URI (only PUT/DELETE are), so Laravel correctly reports 405, not a 500.
        $this->get("/inventory/items/{$itemId}")->assertStatus(405);

        $this->get("/inventory/items/{$itemId}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Items/Edit')->where('item.code', 'ITEM-1'));

        $this->put("/inventory/items/{$itemId}", [
            'code' => 'ITEM-1', 'name' => 'Widget (renamed)', 'inventory_category_id' => $categoryId,
            'stock' => 20, 'minimum_stock' => 5, 'unit' => 'pcs', 'status' => 'inactive',
        ])->assertRedirect(route('inventory.items.index'));

        $tenant->run(function () use ($itemId) {
            $item = InventoryItem::query()->find($itemId);
            $this->assertSame('Widget (renamed)', $item->name);
            $this->assertSame('inactive', $item->status);
        });

        $this->post('/inventory/items', [
            'code' => 'ITEM-1', 'name' => 'Dup', 'inventory_category_id' => $categoryId,
            'stock' => 1, 'minimum_stock' => 0, 'unit' => 'pcs', 'status' => 'active',
        ])->assertSessionHasErrors(['code']);

        $this->post('/inventory/items', [
            'code' => 'ITEM-2', 'name' => 'X', 'inventory_category_id' => 999999,
            'stock' => 1, 'minimum_stock' => 0, 'unit' => 'pcs', 'status' => 'active',
        ])->assertSessionHasErrors(['inventory_category_id']);

        $this->post('/inventory/items', [
            'code' => 'ITEM-3', 'name' => 'X', 'inventory_category_id' => $categoryId,
            'stock' => 1, 'minimum_stock' => 0, 'unit' => 'pcs', 'status' => 'bogus',
        ])->assertSessionHasErrors(['status']);

        $ids = [];
        $tenant->run(function () use (&$ids, $categoryId) {
            $ids[] = InventoryItem::query()->create(['code' => 'BULK-A', 'name' => 'A', 'inventory_category_id' => $categoryId, 'stock' => 1, 'minimum_stock' => 0, 'unit' => 'pcs', 'status' => 'active'])->id;
            $ids[] = InventoryItem::query()->create(['code' => 'BULK-B', 'name' => 'B', 'inventory_category_id' => $categoryId, 'stock' => 1, 'minimum_stock' => 0, 'unit' => 'pcs', 'status' => 'active'])->id;
        });
        $this->delete('/inventory/items/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, InventoryItem::query()->whereIn('id', $ids)->count());
        });

        $this->delete("/inventory/items/{$itemId}")->assertRedirect(route('inventory.items.index'));
        $tenant->run(function () use ($itemId) {
            $this->assertNull(InventoryItem::query()->find($itemId));
        });
    }

    public function test_item_index_filters_by_search_and_status(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $category = InventoryCategory::query()->create(['code' => 'CAT-F', 'name' => 'Filter Cat', 'status' => 'active']);
            InventoryItem::query()->create(['code' => 'FIND-ME', 'name' => 'Findable', 'inventory_category_id' => $category->id, 'stock' => 1, 'minimum_stock' => 0, 'unit' => 'pcs', 'status' => 'active']);
            InventoryItem::query()->create(['code' => 'OTHER', 'name' => 'Other', 'inventory_category_id' => $category->id, 'stock' => 1, 'minimum_stock' => 0, 'unit' => 'pcs', 'status' => 'archived']);
        });

        $this->get('/inventory/items?search=FIND-ME')->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.code', 'FIND-ME'));

        $this->get('/inventory/items?status=archived')->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.code', 'OTHER'));

        $this->get('/inventory/items?sort=code&direction=asc&per_page=5')->assertOk();
    }

    public function test_inventory_service_facade_receives_and_issues_stock_and_reports_availability(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('FACADE-1');
            $service = app(InventoryService::class);

            $receipt = $service->receive([
                'warehouse_id' => $warehouse->id, 'receipt_date' => now()->toDateString(),
                'lines' => [['product_id' => $product->id, 'qty' => 10, 'uom_id' => $product->base_uom_id, 'unit_cost' => 5, 'destination_location_id' => $location->id]],
            ]);
            $this->assertSame(GoodsReceipt::STATUS_POSTED, $receipt->status);
            $this->assertSame(10.0, $service->onHandQty($product->id, $warehouse->id, $location->id));

            $reservation = $service->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 3]);
            $this->assertSame(StockReservation::STATUS_ACTIVE, $reservation->status);
            $this->assertSame(7.0, $service->checkAvailability($product->id, $warehouse->id, $location->id));

            $service->release($reservation);
            $this->assertSame(10.0, $service->checkAvailability($product->id, $warehouse->id, $location->id));

            $issue = $service->issue([
                'warehouse_id' => $warehouse->id, 'issue_date' => now()->toDateString(),
                'lines' => [['product_id' => $product->id, 'qty' => 4, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id]],
            ]);
            $this->assertSame(GoodsIssue::STATUS_POSTED, $issue->status);
            $this->assertSame(6.0, $service->onHandQty($product->id, $warehouse->id, $location->id));
        });
    }

    public function test_accounting_company_resolver_returns_null_when_accounting_is_not_entitled(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        // ACCOUNTING not entitled at all -> resolve() short-circuits to null before even
        // looking at companies. `tenants.plan` alone doesn't reliably flip this in a test
        // (entitlements are a separate, tenant-id-keyed concept per CENTRAL_SPECS.md — not
        // recomputed just by mutating the plan column), so stub the feature check directly.
        // Mocking the container binding here is a one-way door for the rest of THIS test
        // (later resolutions would also see the stub), so this stays its own isolated test.
        $tenant->run(function () {
            $this->mock(TenantFeatureService::class, function ($mock) {
                $mock->shouldReceive('enabled')->with('ACCOUNTING')->andReturn(false);
            });
            $this->assertNull(app(AccountingCompanyResolver::class)->resolve());
        });
    }

    public function test_accounting_company_resolver_dispatches_only_with_exactly_one_active_company(): void
    {
        Event::fake([InventoryGoodsReceived::class, InventoryGoodsIssued::class, InventoryStockAdjusted::class]);

        $tenant = $this->loginAsInventoryAdmin();

        // ACCOUNTING enabled, zero companies -> still null.
        $tenant->run(function () {
            $this->assertNull(app(AccountingCompanyResolver::class)->resolve());
        });

        // ACCOUNTING enabled, two active companies -> ambiguous, still null.
        $tenant->run(function () {
            Company::query()->create(['legal_name' => 'Company A', 'is_active' => true]);
            Company::query()->create(['legal_name' => 'Company B', 'is_active' => true]);
            $this->assertNull(app(AccountingCompanyResolver::class)->resolve());
        });

        // Exactly one active company (plus an inactive one, which must be excluded) -> resolves and dispatches.
        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            Company::query()->update(['is_active' => false]);
            $companyId = Company::query()->create(['legal_name' => 'Solo Co', 'is_active' => true])->id;

            $this->assertSame($companyId, app(AccountingCompanyResolver::class)->resolve());

            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('SOLO-1');
            app(InventoryService::class)->receive([
                'warehouse_id' => $warehouse->id, 'receipt_date' => now()->toDateString(),
                'lines' => [['product_id' => $product->id, 'qty' => 2, 'uom_id' => $product->base_uom_id, 'unit_cost' => 3, 'destination_location_id' => $location->id]],
            ]);

            app(InventoryService::class)->issue([
                'warehouse_id' => $warehouse->id, 'issue_date' => now()->toDateString(),
                'lines' => [['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'source_location_id' => $location->id]],
            ]);

            $reason = $this->makeAdjustmentReason();
            $adjustment = app(AdjustmentService::class)->create([
                'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'adjustment_date' => now()->toDateString(),
                'reason_id' => $reason->id, 'lines' => [['product_id' => $product->id, 'system_qty' => 1, 'counted_qty' => 5]],
            ]);
            app(AdjustmentService::class)->post($adjustment);
        });
        Event::assertDispatched(InventoryGoodsReceived::class, fn ($event) => $event->companyId === $companyId);
        Event::assertDispatched(InventoryGoodsIssued::class, fn ($event) => $event->companyId === $companyId);
        Event::assertDispatched(InventoryStockAdjusted::class, fn ($event) => $event->companyId === $companyId);
    }

    public function test_rebuild_stock_balances_reconstructs_the_cache_from_the_ledger(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $productId = null;
        $otherProductId = null;
        $tenant->run(function () use (&$productId, &$otherProductId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('REBUILD-1');
            $productId = $product->id;
            $otherProduct = $this->makeProduct('REBUILD-2');
            $otherProductId = $otherProduct->id;

            StockLedger::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'movement_type' => StockLedger::TYPE_RECEIPT, 'qty' => 10, 'unit_cost' => 1, 'total_value' => 10, 'movement_date' => now()]);
            StockLedger::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'movement_type' => StockLedger::TYPE_ISSUE, 'qty' => -3, 'unit_cost' => 1, 'total_value' => -3, 'movement_date' => now()]);
            StockLedger::query()->create(['product_id' => $otherProduct->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'movement_type' => StockLedger::TYPE_RECEIPT, 'qty' => 5, 'unit_cost' => 1, 'total_value' => 5, 'movement_date' => now()]);

            // A stale, wrong balance row that the rebuild must overwrite, not merely top up.
            StockBalance::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 999]);

            $rebuild = app(StockBalanceRebuildService::class);
            $this->assertSame(7.0, $rebuild->ledgerTotal($product->id, $warehouse->id, $location->id));

            $count = $rebuild->rebuild($product->id);
            $this->assertSame(1, $count);
        });

        $tenant->run(function () use ($productId, $otherProductId) {
            $this->assertSame('7.0000', StockBalance::query()->where('product_id', $productId)->first()->qty_on_hand);
            // Scoped to $productId only -> the other product's balance was never touched (still absent).
            $this->assertSame(0, StockBalance::query()->where('product_id', $otherProductId)->count());
        });

        $this->artisan('inventory:rebuild-stock-balances')->assertSuccessful();

        $tenant->run(function () use ($otherProductId) {
            // The unscoped run now covers every product.
            $this->assertSame('5.0000', StockBalance::query()->where('product_id', $otherProductId)->first()->qty_on_hand);
        });
    }

    /**
     * Inverse/rarely-navigated Eloquent relations no controller or service ever eager-loads or
     * lazily touches in the normal request flow — same "write the inverse-relations test as its
     * own thing" pattern used for Schedule/CRM/Projects. Each assertion just confirms the
     * relation resolves to the right related row; the business meaning is already covered
     * elsewhere.
     */
    public function test_inverse_and_otherwise_unreached_model_relations_resolve_correctly(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $parentLocation = $this->makeLocation($warehouse, 'PARENT-LOC');
            $childLocation = $this->makeLocation($warehouse, 'CHILD-LOC');
            $childLocation->update(['parent_location_id' => $parentLocation->id]);
            $childLocation->refresh();
            $this->assertSame($warehouse->id, $childLocation->warehouse->id);
            $this->assertSame($parentLocation->id, $childLocation->parent->id);

            $reason = $this->makeAdjustmentReason('rel_test', 'Relation Test');
            $adjustment = Adjustment::query()->create([
                'warehouse_id' => $warehouse->id, 'location_id' => $parentLocation->id,
                'adjustment_date' => now()->toDateString(), 'reason_id' => $reason->id, 'status' => Adjustment::STATUS_DRAFT,
            ]);
            $this->assertSame($adjustment->id, $reason->adjustments()->first()->id);
            $this->assertSame($parentLocation->id, $adjustment->location->id);

            $parentCategory = $this->makeCategory('Parent Cat');
            $childCategory = ProductCategory::query()->create(['parent_category_id' => $parentCategory->id, 'name' => 'Child Cat', 'is_active' => true]);
            $this->assertSame($parentCategory->id, $childCategory->parent->id);

            $item = InventoryCategory::query()->create(['code' => 'REL-CAT', 'name' => 'Rel Item Category', 'status' => 'active']);
            InventoryItem::query()->create(['code' => 'REL-ITEM', 'name' => 'Rel Item', 'inventory_category_id' => $item->id, 'stock' => 1, 'minimum_stock' => 0, 'unit' => 'pcs', 'status' => 'active']);
            $this->assertSame(1, $item->items()->count());
            $this->assertNotNull(InventoryItem::factory()->make());

            $userId = User::query()->first()->id;
            $product = $this->makeProduct('REL-PROD', ['tracking_mode' => Product::TRACKING_BATCH]);
            $altUom = $this->makeUom('REL-UOM', 'Relation Uom');
            $batch = app(BatchService::class)->resolve($product->id, 'REL-LOT');

            $adjustmentLine = $adjustment->lines()->create(['product_id' => $product->id, 'system_qty' => 1, 'counted_qty' => 1]);
            $this->assertSame($adjustment->id, $adjustmentLine->adjustment->id);

            $balance = StockBalance::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $parentLocation->id, 'batch_id' => $batch->id, 'qty_on_hand' => 1]);
            $this->assertSame($warehouse->id, $balance->warehouse->id);
            $this->assertSame($parentLocation->id, $balance->location->id);
            $this->assertSame($batch->id, $balance->batch->id);

            $ledger = StockLedger::query()->create([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $parentLocation->id, 'batch_id' => $batch->id,
                'movement_type' => StockLedger::TYPE_RECEIPT, 'qty' => 1, 'unit_cost' => 1, 'total_value' => 1, 'movement_date' => now(),
            ]);
            $this->assertSame($batch->id, $ledger->batch->id);

            $serial = StockSerial::query()->create(['product_id' => $product->id, 'serial_number' => 'REL-SN', 'status' => StockSerial::STATUS_IN_STOCK, 'warehouse_id' => $warehouse->id, 'location_id' => $parentLocation->id, 'stock_ledger_id' => $ledger->id]);
            $this->assertSame($ledger->id, $serial->stockLedger->id);

            $layer = StockValuationLayer::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_id' => $batch->id, 'unit_cost' => 1, 'qty' => 1, 'remaining_qty' => 1]);
            $this->assertSame($batch->id, $layer->batch->id);

            $receipt = GoodsReceipt::query()->create(['warehouse_id' => $warehouse->id, 'receipt_date' => now(), 'status' => GoodsReceipt::STATUS_DRAFT]);
            $receiptLine = $receipt->lines()->create(['product_id' => $product->id, 'batch_id' => $batch->id, 'qty' => 1, 'uom_id' => $altUom->id, 'unit_cost' => 1, 'destination_location_id' => $parentLocation->id]);
            $this->assertSame($receipt->id, $receiptLine->receipt->id);
            $this->assertSame($altUom->id, $receiptLine->uom->id);
            $this->assertSame($parentLocation->id, $receiptLine->destinationLocation->id);

            $issue = GoodsIssue::query()->create(['warehouse_id' => $warehouse->id, 'issue_date' => now(), 'status' => GoodsIssue::STATUS_DRAFT]);
            $issueLine = $issue->lines()->create(['product_id' => $product->id, 'batch_id' => $batch->id, 'qty' => 1, 'uom_id' => $altUom->id, 'source_location_id' => $parentLocation->id]);
            $this->assertSame($issue->id, $issueLine->issue->id);
            $this->assertSame($altUom->id, $issueLine->uom->id);
            $this->assertSame($parentLocation->id, $issueLine->sourceLocation->id);

            $transfer = Transfer::query()->create([
                'source_warehouse_id' => $warehouse->id, 'source_location_id' => $parentLocation->id,
                'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $childLocation->id,
                'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT,
            ]);
            $this->assertSame($parentLocation->id, $transfer->sourceLocation->id);
            $this->assertSame($childLocation->id, $transfer->destinationLocation->id);
            $transferLine = $transfer->lines()->create(['product_id' => $product->id, 'batch_id' => $batch->id, 'qty' => 1, 'uom_id' => $altUom->id]);
            $this->assertSame($transfer->id, $transferLine->transfer->id);
            $this->assertSame($altUom->id, $transferLine->uom->id);

            $conversion = UomConversion::query()->create(['product_id' => $product->id, 'uom_id' => $altUom->id, 'conversion_factor' => 2]);
            $this->assertSame($product->id, $conversion->product->id);
            $this->assertSame($altUom->id, $conversion->uom->id);

            $reservation = StockReservation::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $parentLocation->id, 'qty' => 1, 'status' => StockReservation::STATUS_FULFILLED]);
            $pickList = PickList::query()->create(['warehouse_id' => $warehouse->id, 'status' => PickList::STATUS_PENDING]);
            $pickLine = $pickList->lines()->create(['reservation_id' => $reservation->id, 'product_id' => $product->id, 'batch_id' => $batch->id, 'location_id' => $parentLocation->id, 'qty' => 1, 'confirmed_qty' => 1, 'status' => PickListLine::STATUS_PICKED]);
            $serial2 = StockSerial::query()->create(['product_id' => $product->id, 'serial_number' => 'REL-SN-2', 'status' => StockSerial::STATUS_IN_STOCK, 'warehouse_id' => $warehouse->id, 'location_id' => $parentLocation->id]);
            $pickLine->update(['serial_id' => $serial2->id]);

            $packList = PackList::query()->create(['warehouse_id' => $warehouse->id, 'pick_list_id' => $pickList->id, 'status' => PackList::STATUS_PACKED, 'packed_by' => $userId, 'packed_at' => now()]);
            $this->assertSame($userId, $packList->packedBy->id);
            $packListLine = PackListLine::query()->create(['pack_list_id' => $packList->id, 'pick_list_line_id' => $pickLine->id, 'product_id' => $product->id, 'batch_id' => $batch->id, 'serial_id' => $serial2->id, 'qty' => 1]);
            $this->assertSame($packList->id, $packListLine->packList->id);
            $this->assertSame($serial2->id, $packListLine->serial->id);
            $this->assertSame($batch->id, $packListLine->batch->id);

            $shipment = Shipment::query()->create(['warehouse_id' => $warehouse->id, 'status' => Shipment::STATUS_SHIPPED, 'shipped_by' => $userId, 'shipped_at' => now()]);
            $this->assertSame($userId, $shipment->shippedBy->id);

            $cycleCount = CycleCount::query()->create(['warehouse_id' => $warehouse->id, 'location_id' => $parentLocation->id, 'status' => CycleCount::STATUS_COMPLETED]);
            $cycleCountLine = $cycleCount->lines()->create(['product_id' => $product->id, 'location_id' => $parentLocation->id, 'system_qty' => 1, 'counted_qty' => 1, 'status' => CycleCountLine::STATUS_COUNTED, 'counted_at' => now(), 'counted_by' => $userId]);
            $this->assertSame($userId, $cycleCountLine->countedBy->id);
        });
    }
}
