<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Inventory\Models\UomConversion;
use App\Modules\Inventory\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3F — Transfer: draft CRUD plus post() (paired issue-at-source + receipt-at-destination) and complete(). */
class TransferTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    private function seedOnHandStock(int $productId, int $warehouseId, int $locationId, float $qty, float $unitCost = 4.0): void
    {
        StockBalance::query()->create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'qty_on_hand' => $qty]);
        StockValuationLayer::query()->create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'unit_cost' => $unitCost, 'qty' => $qty, 'remaining_qty' => $qty]);
    }

    public function test_admin_can_crud_a_draft_transfer(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $sourceLocId = null;
        $destLocId = null;
        $productId = null;
        $uomId = null;
        $tenant->run(function () use (&$warehouseId, &$sourceLocId, &$destLocId, &$productId, &$uomId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $sourceLocId = $this->makeLocation($warehouse, 'SRC')->id;
            $destLocId = $this->makeLocation($warehouse, 'DEST')->id;
            $product = $this->makeProduct();
            $productId = $product->id;
            $uomId = $product->base_uom_id;
        });

        $this->get('/inventory/transfers')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Transfers/Index'));
        $this->get('/inventory/transfers/create')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Transfers/Create'));

        $this->post('/inventory/transfers', [
            'source_warehouse_id' => $warehouseId, 'source_location_id' => $sourceLocId,
            'destination_warehouse_id' => $warehouseId, 'destination_location_id' => $destLocId,
            'transfer_date' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'qty' => 3, 'uom_id' => $uomId]],
        ])->assertRedirect();

        $transferId = null;
        $tenant->run(function () use (&$transferId) {
            $transfer = Transfer::query()->first();
            $this->assertNotNull($transfer);
            $this->assertSame(1, $transfer->lines()->count());
            $transferId = $transfer->id;
        });

        $this->get("/inventory/transfers/{$transferId}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Transfers/Edit'));

        $this->put("/inventory/transfers/{$transferId}", [
            'source_warehouse_id' => $warehouseId, 'source_location_id' => $sourceLocId,
            'destination_warehouse_id' => $warehouseId, 'destination_location_id' => $destLocId,
            'transfer_date' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'qty' => 7, 'uom_id' => $uomId]],
        ])->assertRedirect();

        $tenant->run(function () use ($transferId) {
            $this->assertSame('7.0000', Transfer::query()->find($transferId)->lines()->first()->qty);
        });

        $this->delete("/inventory/transfers/{$transferId}")->assertRedirect(route('inventory.transfers.index'));
        $tenant->run(function () use ($transferId) {
            $this->assertNull(Transfer::query()->find($transferId));
        });
    }

    public function test_index_filters_by_status_and_sorts(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $a = $this->makeLocation($warehouse, 'A');
            $b = $this->makeLocation($warehouse, 'B');
            Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $a->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $b->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $a->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $b->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_COMPLETED]);
        });

        $this->get('/inventory/transfers?status=completed')->assertOk()
            ->assertInertia(fn ($page) => $page->has('transfers.data', 1)->where('transfers.data.0.status', 'completed'));

        $this->get('/inventory/transfers?sort=transfer_date&direction=asc&per_page=5')->assertOk();
    }

    public function test_store_validation_rejects_invalid_locations_same_location_and_invalid_line_refs(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $locationId = $this->makeLocation($warehouse)->id;
        });

        $this->post('/inventory/transfers', [
            'source_warehouse_id' => $warehouseId, 'source_location_id' => 999999,
            'destination_warehouse_id' => $warehouseId, 'destination_location_id' => 999998,
            'transfer_date' => now()->toDateString(), 'lines' => [],
        ])->assertSessionHasErrors(['source_location_id', 'destination_location_id', 'lines']);

        $this->post('/inventory/transfers', [
            'source_warehouse_id' => $warehouseId, 'source_location_id' => $locationId,
            'destination_warehouse_id' => $warehouseId, 'destination_location_id' => $locationId,
            'transfer_date' => now()->toDateString(), 'lines' => [['product_id' => 999999, 'qty' => 1, 'uom_id' => 999999]],
        ])->assertSessionHasErrors(['destination_location_id', 'lines.0.product_id', 'lines.0.uom_id']);
    }

    public function test_update_validation_rejects_invalid_locations_and_line_refs(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $transferId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId, &$transferId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $transfer = Transfer::query()->create([
                'source_warehouse_id' => $warehouse->id, 'source_location_id' => $location->id,
                'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $this->makeLocation($warehouse, 'DEST')->id,
                'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT,
            ]);
            $transferId = $transfer->id;
        });

        $this->put("/inventory/transfers/{$transferId}", [
            'source_warehouse_id' => $warehouseId, 'source_location_id' => 999999,
            'destination_warehouse_id' => $warehouseId, 'destination_location_id' => 999998,
            'transfer_date' => now()->toDateString(), 'lines' => [],
        ])->assertSessionHasErrors(['source_location_id', 'destination_location_id', 'lines']);

        $this->put("/inventory/transfers/{$transferId}", [
            'source_warehouse_id' => $warehouseId, 'source_location_id' => $locationId,
            'destination_warehouse_id' => $warehouseId, 'destination_location_id' => $locationId,
            'transfer_date' => now()->toDateString(), 'lines' => [['product_id' => 999999, 'qty' => 1, 'uom_id' => 999999]],
        ])->assertSessionHasErrors(['destination_location_id', 'lines.0.product_id', 'lines.0.uom_id']);
    }

    public function test_posting_a_same_warehouse_transfer_completes_immediately_and_moves_stock(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $transferId = null;
        $warehouseId = null;
        $sourceLocId = null;
        $destLocId = null;
        $productId = null;
        $tenant->run(function () use (&$transferId, &$warehouseId, &$sourceLocId, &$destLocId, &$productId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $source = $this->makeLocation($warehouse, 'SRC');
            $sourceLocId = $source->id;
            $dest = $this->makeLocation($warehouse, 'DEST');
            $destLocId = $dest->id;
            $product = $this->makeProduct('XFER-1');
            $productId = $product->id;
            $this->seedOnHandStock($product->id, $warehouse->id, $source->id, 10);

            $transfer = Transfer::query()->create([
                'source_warehouse_id' => $warehouse->id, 'source_location_id' => $source->id,
                'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $dest->id,
                'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT,
            ]);
            $transfer->lines()->create(['product_id' => $product->id, 'qty' => 4, 'uom_id' => $product->base_uom_id]);
            $transferId = $transfer->id;
        });

        $this->patch("/inventory/transfers/{$transferId}/post")->assertRedirect(route('inventory.transfers.edit', $transferId));

        $tenant->run(function () use ($transferId, $warehouseId, $sourceLocId, $destLocId, $productId) {
            $transfer = Transfer::query()->find($transferId);
            $this->assertSame(Transfer::STATUS_COMPLETED, $transfer->status);
            $this->assertNotNull($transfer->completed_at);

            $sourceBalance = StockBalance::query()->where('product_id', $productId)->where('location_id', $sourceLocId)->first();
            $this->assertSame('6.0000', $sourceBalance->qty_on_hand);

            $destBalance = StockBalance::query()->where('product_id', $productId)->where('location_id', $destLocId)->first();
            $this->assertSame('4.0000', $destBalance->qty_on_hand);

            $this->assertSame(1, StockValuationLayer::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('remaining_qty', 4)->count());
        });

        // Draft-only actions blocked, and complete() only applies to in-transit transfers.
        $this->delete("/inventory/transfers/{$transferId}")->assertSessionHasErrors(['status']);
        $this->patch("/inventory/transfers/{$transferId}/post")->assertSessionHasErrors(['status']);
        $this->patch("/inventory/transfers/{$transferId}/complete")->assertSessionHasErrors(['status']);
    }

    public function test_posting_a_cross_warehouse_transfer_stays_in_transit_until_completed(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $transferId = null;
        $tenant->run(function () use (&$transferId) {
            $sourceWarehouse = $this->makeWarehouse('Source WH');
            $destWarehouse = $this->makeWarehouse('Dest WH');
            $source = $this->makeLocation($sourceWarehouse, 'SRC');
            $dest = $this->makeLocation($destWarehouse, 'DEST');
            $product = $this->makeProduct('XFER-2');
            $this->seedOnHandStock($product->id, $sourceWarehouse->id, $source->id, 5);

            $transfer = Transfer::query()->create([
                'source_warehouse_id' => $sourceWarehouse->id, 'source_location_id' => $source->id,
                'destination_warehouse_id' => $destWarehouse->id, 'destination_location_id' => $dest->id,
                'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT,
            ]);
            $transfer->lines()->create(['product_id' => $product->id, 'qty' => 2, 'uom_id' => $product->base_uom_id]);
            $transferId = $transfer->id;
        });

        // complete() before post() rejects — not yet in-transit.
        $this->patch("/inventory/transfers/{$transferId}/complete")->assertSessionHasErrors(['status']);

        $this->patch("/inventory/transfers/{$transferId}/post")->assertRedirect();
        $tenant->run(function () use ($transferId) {
            $this->assertSame(Transfer::STATUS_IN_TRANSIT, Transfer::query()->find($transferId)->status);
        });

        $this->patch("/inventory/transfers/{$transferId}/complete")->assertRedirect();
        $tenant->run(function () use ($transferId) {
            $transfer = Transfer::query()->find($transferId);
            $this->assertSame(Transfer::STATUS_COMPLETED, $transfer->status);
            $this->assertNotNull($transfer->completed_at);
        });

        $this->patch("/inventory/transfers/{$transferId}/complete")->assertSessionHasErrors(['status']);
    }

    public function test_posting_is_blocked_with_no_lines_same_location_insufficient_stock_or_foreign_location(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $emptyId = null;
        $sameLocationId = null;
        $shortId = null;
        $foreignId = null;
        $tenant->run(function () use (&$emptyId, &$sameLocationId, &$shortId, &$foreignId) {
            $warehouse = $this->makeWarehouse();
            $source = $this->makeLocation($warehouse, 'SRC');
            $dest = $this->makeLocation($warehouse, 'DEST');
            $otherWarehouse = $this->makeWarehouse('Other WH');
            $foreignLocation = $this->makeLocation($otherWarehouse, 'FOREIGN');
            $product = $this->makeProduct('BLOCK-1');
            $this->seedOnHandStock($product->id, $warehouse->id, $source->id, 1);

            $empty = Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $source->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $dest->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            $emptyId = $empty->id;

            // Bypasses the FormRequest's own same-location check — only reachable this way.
            $sameLocation = Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $source->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $source->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            $sameLocation->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id]);
            $sameLocationId = $sameLocation->id;

            $short = Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $source->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $dest->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            $short->lines()->create(['product_id' => $product->id, 'qty' => 999, 'uom_id' => $product->base_uom_id]);
            $shortId = $short->id;

            // FormRequest only checks the location exists, not that it belongs to the header's warehouse.
            $foreign = Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $foreignLocation->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $dest->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            $foreign->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id]);
            $foreignId = $foreign->id;
        });

        $this->patch("/inventory/transfers/{$emptyId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/transfers/{$sameLocationId}/post")->assertSessionHasErrors(['destination_location_id']);
        $this->patch("/inventory/transfers/{$shortId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/transfers/{$foreignId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_posting_is_blocked_for_inactive_product(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $transferId = null;
        $tenant->run(function () use (&$transferId) {
            $warehouse = $this->makeWarehouse();
            $source = $this->makeLocation($warehouse, 'SRC');
            $dest = $this->makeLocation($warehouse, 'DEST');
            $product = $this->makeProduct('INACTIVE-XFER');
            $this->seedOnHandStock($product->id, $warehouse->id, $source->id, 5);
            $product->update(['is_active' => false]);

            $transfer = Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $source->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $dest->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            $transfer->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id]);
            $transferId = $transfer->id;
        });

        $this->patch("/inventory/transfers/{$transferId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_posting_a_batch_tracked_line_requires_a_batch(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $transferId = null;
        $tenant->run(function () use (&$transferId) {
            $warehouse = $this->makeWarehouse();
            $source = $this->makeLocation($warehouse, 'SRC');
            $dest = $this->makeLocation($warehouse, 'DEST');
            $product = $this->makeProduct('BATCH-XFER', ['tracking_mode' => Product::TRACKING_BATCH]);
            $this->seedOnHandStock($product->id, $warehouse->id, $source->id, 5);

            $transfer = Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $source->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $dest->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            $transfer->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'batch_id' => null]);
            $transferId = $transfer->id;
        });

        $this->patch("/inventory/transfers/{$transferId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_posting_a_serial_tracked_line_requires_matching_serial_count_and_moves_serials(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $transferId = null;
        $productId = null;
        $destLocId = null;
        $serialNumber = 'SN-XFER-1';
        $tenant->run(function () use (&$transferId, &$productId, &$destLocId, $serialNumber) {
            $warehouse = $this->makeWarehouse();
            $source = $this->makeLocation($warehouse, 'SRC');
            $dest = $this->makeLocation($warehouse, 'DEST');
            $destLocId = $dest->id;
            $product = $this->makeProduct('SERIAL-XFER', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $productId = $product->id;
            $this->seedOnHandStock($product->id, $warehouse->id, $source->id, 1);
            StockSerial::query()->create([
                'product_id' => $product->id, 'serial_number' => $serialNumber, 'status' => StockSerial::STATUS_IN_STOCK,
                'warehouse_id' => $warehouse->id, 'location_id' => $source->id,
            ]);

            $transfer = Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $source->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $dest->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            $transfer->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'serial_numbers' => [$serialNumber]]);
            $transferId = $transfer->id;
        });

        $this->patch("/inventory/transfers/{$transferId}/post")->assertRedirect();

        $tenant->run(function () use ($productId, $destLocId, $serialNumber) {
            $serial = StockSerial::query()->where('product_id', $productId)->where('serial_number', $serialNumber)->first();
            $this->assertSame(StockSerial::STATUS_IN_STOCK, $serial->status);
            $this->assertSame($destLocId, $serial->location_id);
        });
    }

    public function test_posting_a_serial_tracked_transfer_rejects_a_count_mismatch_a_fractional_qty_and_a_serial_not_at_the_source(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $mismatchId = null;
        $fractionalId = null;
        $notAtSourceId = null;
        $tenant->run(function () use (&$mismatchId, &$fractionalId, &$notAtSourceId) {
            $warehouse = $this->makeWarehouse();
            $source = $this->makeLocation($warehouse, 'SRC');
            $dest = $this->makeLocation($warehouse, 'DEST');
            $elsewhere = $this->makeLocation($warehouse, 'ELSEWHERE');
            $product = $this->makeProduct('SERIAL-XFER-EDGE', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $this->seedOnHandStock($product->id, $warehouse->id, $source->id, 2);

            // Two units claimed but only one serial named.
            $mismatchTransfer = Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $source->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $dest->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            $mismatchTransfer->lines()->create(['product_id' => $product->id, 'qty' => 2, 'uom_id' => $product->base_uom_id, 'serial_numbers' => ['SN-ONLY-ONE']]);
            $mismatchId = $mismatchTransfer->id;

            // A UoM conversion factor that doesn't divide evenly yields a fractional base qty.
            $looseUom = $this->makeUom('LOOSE-UOM', 'Loose Pack');
            UomConversion::query()->create(['product_id' => $product->id, 'uom_id' => $looseUom->id, 'conversion_factor' => 1.5]);
            $fractionalTransfer = Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $source->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $dest->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            $fractionalTransfer->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $looseUom->id, 'serial_numbers' => ['SN-FRACTIONAL']]);
            $fractionalId = $fractionalTransfer->id;

            // Serial genuinely exists, but sitting at a different location than the transfer's source.
            StockSerial::query()->create(['product_id' => $product->id, 'serial_number' => 'SN-ELSEWHERE-XFER', 'status' => StockSerial::STATUS_IN_STOCK, 'warehouse_id' => $warehouse->id, 'location_id' => $elsewhere->id]);
            $notAtSourceTransfer = Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $source->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $dest->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);
            $notAtSourceTransfer->lines()->create(['product_id' => $product->id, 'qty' => 1, 'uom_id' => $product->base_uom_id, 'serial_numbers' => ['SN-ELSEWHERE-XFER']]);
            $notAtSourceId = $notAtSourceTransfer->id;
        });

        $this->patch("/inventory/transfers/{$mismatchId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/transfers/{$fractionalId}/post")->assertSessionHasErrors(['lines']);
        $this->patch("/inventory/transfers/{$notAtSourceId}/post")->assertSessionHasErrors(['lines']);
    }

    /** UpdateTransferRequest's own rules require every line's product_id/qty, so this "skip a
     *  blank line" defensive branch is only reachable via a direct service call (e.g. an
     *  internal caller that doesn't go through the HTTP FormRequest), not through the UI. */
    public function test_transfer_service_update_silently_drops_a_blank_line(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse, 'SRC');
            $destLocation = $this->makeLocation($warehouse, 'DEST');
            $product = $this->makeProduct();
            $transfer = Transfer::query()->create(['source_warehouse_id' => $warehouse->id, 'source_location_id' => $location->id, 'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $destLocation->id, 'transfer_date' => now(), 'status' => Transfer::STATUS_DRAFT]);

            app(TransferService::class)->update($transfer, [
                'source_warehouse_id' => $warehouse->id, 'source_location_id' => $location->id,
                'destination_warehouse_id' => $warehouse->id, 'destination_location_id' => $destLocation->id,
                'transfer_date' => now()->toDateString(),
                'lines' => [
                    ['product_id' => $product->id, 'qty' => 3, 'uom_id' => $product->base_uom_id],
                    ['product_id' => null, 'qty' => null, 'uom_id' => $product->base_uom_id],
                ],
            ]);

            $this->assertSame(1, $transfer->lines()->count());
        });
    }
}
