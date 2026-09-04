<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\GoodsIssue;
use App\Modules\Inventory\Models\PackList;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\PackListService;
use App\Modules\Inventory\Services\PickListService;
use App\Modules\Inventory\Services\ReservationService;
use App\Modules\Inventory\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpInventory;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3N/§3O/§3P — Reservation -> Pick List -> Pack List -> Shipment chain, ending in a real Goods Issue on ship-confirm. */
class ReservationPickPackShipTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpInventory;
    use SetsUpTenant;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedOnHandStock(int $productId, int $warehouseId, int $locationId, float $qty, float $unitCost = 4.0): void
    {
        StockBalance::query()->create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'qty_on_hand' => $qty]);
        StockValuationLayer::query()->create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'unit_cost' => $unitCost, 'qty' => $qty, 'remaining_qty' => $qty]);
    }

    public function test_full_chain_from_reservation_through_shipped_and_delivered_posts_a_goods_issue(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $locationId = null;
        $productId = null;
        $reservationId = null;
        $tenant->run(function () use (&$warehouseId, &$locationId, &$productId, &$reservationId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $product = $this->makeProduct('CHAIN-1');
            $productId = $product->id;
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 10);

            $reservation = app(ReservationService::class)->reserve([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 4,
                'subject_type' => 'test.order', 'subject_id' => '1',
            ]);
            $reservationId = $reservation->id;
        });

        $this->get('/inventory/reservations')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Reservations/Index')->has('reservations.data', 1));

        $this->post('/inventory/reservations/generate-pick-list', ['ids' => [$reservationId]])->assertRedirect(route('inventory.pickLists.index'));

        $pickListId = null;
        $pickLineId = null;
        $tenant->run(function () use (&$pickListId, &$pickLineId, $reservationId) {
            $line = PickListLine::query()->where('reservation_id', $reservationId)->first();
            $this->assertNotNull($line);
            $pickListId = $line->pick_list_id;
            $pickLineId = $line->id;
            $this->assertSame(PickListLine::STATUS_PENDING, $line->status);
        });

        $this->get('/inventory/pick-lists')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/PickLists/Index'));
        $this->get("/inventory/pick-lists/{$pickListId}")->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/PickLists/Show'));
        $this->patch("/inventory/pick-lists/{$pickListId}/assign", ['assigned_to' => null])->assertRedirect();

        $this->patch("/inventory/pick-lists/{$pickListId}/lines/{$pickLineId}/pick", ['confirmed_qty' => 4])->assertRedirect();

        $tenant->run(function () use ($pickListId, $reservationId) {
            $this->assertSame(PickList::STATUS_READY_FOR_PACKING, PickList::query()->find($pickListId)->status);
            $this->assertSame(StockReservation::STATUS_FULFILLED, StockReservation::query()->find($reservationId)->status);
        });

        $this->get("/inventory/pack-lists/create?pick_list_id={$pickListId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/PackLists/Create')->has('availableLines', 1));

        $this->post('/inventory/pack-lists', [
            'pick_list_id' => $pickListId, 'package_type' => PackList::TYPE_CARTON,
            'lines' => [['pick_list_line_id' => $pickLineId, 'qty' => 4]],
        ])->assertRedirect();

        $packListId = null;
        $tenant->run(function () use (&$packListId, $pickListId) {
            $packList = PackList::query()->where('pick_list_id', $pickListId)->first();
            $this->assertNotNull($packList);
            $this->assertSame(PackList::STATUS_PACKED, $packList->status);
            $packListId = $packList->id;
        });

        $this->get("/inventory/pack-lists/{$packListId}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/PackLists/Edit'));
        $this->put("/inventory/pack-lists/{$packListId}", [
            'package_type' => PackList::TYPE_PALLET, 'lines' => [['pick_list_line_id' => $pickLineId, 'qty' => 4]],
        ])->assertRedirect();
        $tenant->run(function () use ($packListId) {
            $this->assertSame(PackList::TYPE_PALLET, PackList::query()->find($packListId)->package_type);
        });

        $this->get('/inventory/shipments/create')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Shipments/Create')->has('eligiblePackLists', 1));

        $this->post('/inventory/shipments', [
            'warehouse_id' => $warehouseId, 'carrier' => 'DHL', 'tracking_number' => 'TRK-1',
            'pack_list_ids' => [$packListId],
        ])->assertRedirect();

        $shipmentId = null;
        $tenant->run(function () use (&$shipmentId, $packListId) {
            $shipment = Shipment::query()->where('tracking_number', 'TRK-1')->first();
            $this->assertNotNull($shipment);
            $this->assertSame($shipment->id, PackList::query()->find($packListId)->shipment_id);
            $shipmentId = $shipment->id;
        });

        $this->get("/inventory/shipments/{$shipmentId}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Shipments/Edit'));

        $this->patch("/inventory/shipments/{$shipmentId}/ship-confirm")->assertRedirect(route('inventory.shipments.edit', $shipmentId));

        $tenant->run(function () use ($shipmentId, $packListId, $productId, $warehouseId, $locationId) {
            $shipment = Shipment::query()->find($shipmentId);
            $this->assertSame(Shipment::STATUS_SHIPPED, $shipment->status);
            $this->assertNotNull($shipment->goods_issue_id);

            $issue = GoodsIssue::query()->find($shipment->goods_issue_id);
            $this->assertSame(GoodsIssue::STATUS_POSTED, $issue->status);
            $this->assertSame('inventory.shipments', $issue->subject_type);

            $this->assertSame(PackList::STATUS_SHIPPED, PackList::query()->find($packListId)->status);

            $balance = StockBalance::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('location_id', $locationId)->first();
            $this->assertSame('6.0000', $balance->qty_on_hand);
        });

        // Pending-only actions blocked once shipped.
        $this->put("/inventory/shipments/{$shipmentId}", ['carrier' => 'X', 'pack_list_ids' => [$packListId]])->assertSessionHasErrors(['status']);
        $this->delete("/inventory/shipments/{$shipmentId}")->assertSessionHasErrors(['status']);

        $this->patch("/inventory/shipments/{$shipmentId}/deliver")->assertRedirect();
        $tenant->run(function () use ($shipmentId) {
            $shipment = Shipment::query()->find($shipmentId);
            $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->status);
            $this->assertNotNull($shipment->delivered_at);
        });
        $this->patch("/inventory/shipments/{$shipmentId}/deliver")->assertSessionHasErrors(['status']);
    }

    public function test_reserve_validates_product_status_availability_and_batch_ownership(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $service = app(ReservationService::class);
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);

            $inactive = $this->makeProduct('RES-INACTIVE');
            $inactive->update(['is_active' => false]);
            try {
                $service->reserve(['product_id' => $inactive->id, 'warehouse_id' => $warehouse->id, 'qty' => 1]);
                $this->fail('Expected a ValidationException for an inactive product.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('product_id', $e->errors());
            }

            $product = $this->makeProduct('RES-SHORT');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 2);
            try {
                $service->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 100]);
                $this->fail('Expected a ValidationException for over-reserving.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('qty', $e->errors());
            }

            $otherProduct = $this->makeProduct('RES-OTHER');
            $foreignBatch = app(BatchService::class)->resolve($otherProduct->id, 'FOREIGN-LOT');
            try {
                $service->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_id' => $foreignBatch->id, 'qty' => 1]);
                $this->fail('Expected a ValidationException for a batch belonging to a different product.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('batch_id', $e->errors());
            }
        });
    }

    public function test_reserve_pins_a_specific_serial_and_flips_its_status(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('RES-SERIAL', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $serial = StockSerial::query()->create([
                'product_id' => $product->id, 'serial_number' => 'SN-RES-1', 'status' => StockSerial::STATUS_IN_STOCK,
                'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            ]);
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 1);

            $reservation = app(ReservationService::class)->reserve([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'serial_id' => $serial->id,
            ]);

            $this->assertSame(1, (int) $reservation->qty);
            $this->assertSame($location->id, $reservation->location_id);
            $this->assertSame(StockSerial::STATUS_RESERVED, $serial->refresh()->status);

            try {
                app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'serial_id' => $serial->id]);
                $this->fail('Expected a ValidationException for a serial that is not in_stock.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('serial_id', $e->errors());
            }

            try {
                app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'serial_id' => 999999]);
                $this->fail('Expected a ValidationException for a serial that does not belong to this product.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('serial_id', $e->errors());
            }

            app(ReservationService::class)->release($reservation);
            $this->assertSame(StockSerial::STATUS_IN_STOCK, $serial->refresh()->status);

            try {
                app(ReservationService::class)->release($reservation);
                $this->fail('Expected a ValidationException releasing an already-released reservation.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }

    public function test_release_expired_reservations_command_flips_status_to_released(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        Carbon::setTestNow('2026-06-15 12:00:00');

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('RES-EXPIRE');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5);

            app(ReservationService::class)->reserve([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
                'qty' => 1, 'expires_at' => now()->subHour(),
            ]);
        });

        $this->artisan('inventory:release-expired-reservations')->assertSuccessful();

        $tenant->run(function () {
            $reservation = StockReservation::query()->first();
            $this->assertSame(StockReservation::STATUS_RELEASED, $reservation->status);
        });
    }

    public function test_release_endpoint_and_generate_pick_list_validation_and_partial_failure(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $activeId = null;
        $tenant->run(function () use (&$activeId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('RES-RELEASE');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5);

            $reservation = app(ReservationService::class)->reserve([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 1,
            ]);
            $activeId = $reservation->id;
        });

        $this->patch("/inventory/reservations/{$activeId}/release")->assertRedirect(route('inventory.reservations.index'));
        $tenant->run(function () use ($activeId) {
            $this->assertSame(StockReservation::STATUS_RELEASED, StockReservation::query()->find($activeId)->status);
        });

        $this->patch("/inventory/reservations/{$activeId}/release")->assertSessionHasErrors(['status']);

        $this->post('/inventory/reservations/generate-pick-list', ['ids' => []])->assertSessionHasErrors(['ids']);

        // Every id inactive/nonexistent -> generate() throws top-level.
        $this->post('/inventory/reservations/generate-pick-list', ['ids' => [$activeId]])->assertSessionHasErrors(['reservations']);
    }

    public function test_generate_pick_list_resolves_an_unassigned_reservation_to_a_location_with_enough_stock(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $reservationId = null;
        $goodLocationId = null;
        $tenant->run(function () use (&$reservationId, &$goodLocationId) {
            $warehouse = $this->makeWarehouse();
            $shortLocation = $this->makeLocation($warehouse, 'SHORT');
            $goodLocation = $this->makeLocation($warehouse, 'GOOD');
            $goodLocationId = $goodLocation->id;
            $product = $this->makeProduct('RES-UNASSIGNED');
            $this->seedOnHandStock($product->id, $warehouse->id, $shortLocation->id, 1);
            $this->seedOnHandStock($product->id, $warehouse->id, $goodLocation->id, 10);

            // No location_id -> unassigned, resolved at pick-list generation time.
            $reservation = app(ReservationService::class)->reserve([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'qty' => 5,
            ]);
            $reservationId = $reservation->id;
        });

        $this->post('/inventory/reservations/generate-pick-list', ['ids' => [$reservationId]])->assertRedirect();

        $tenant->run(function () use ($reservationId, $goodLocationId) {
            $line = PickListLine::query()->where('reservation_id', $reservationId)->first();
            $this->assertSame($goodLocationId, $line->location_id);
        });
    }

    public function test_pick_list_delete_is_blocked_once_it_has_picked_lines(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $pickListId = null;
        $pickLineId = null;
        $tenant->run(function () use (&$pickListId, &$pickLineId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('PICK-DELETE');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5);

            $reservation = app(ReservationService::class)->reserve([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 2,
            ]);

            $result = app(PickListService::class)->generate([$reservation->id]);
            $pickList = $result['lists']->first();
            $pickListId = $pickList->id;
            $pickLineId = $pickList->lines->first()->id;
        });

        $this->patch("/inventory/pick-lists/{$pickListId}/lines/{$pickLineId}/pick", ['confirmed_qty' => 2])->assertRedirect();
        $this->patch("/inventory/pick-lists/{$pickListId}/lines/{$pickLineId}/pick", ['confirmed_qty' => 2])->assertSessionHasErrors(['status']);

        $this->delete("/inventory/pick-lists/{$pickListId}")->assertSessionHasErrors(['status']);
    }

    public function test_pick_list_delete_succeeds_while_no_line_has_been_picked_yet(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $pickListId = null;
        $tenant->run(function () use (&$pickListId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('PICK-DELETE-OK');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5);

            $reservation = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 2]);
            $pickListId = app(PickListService::class)->generate([$reservation->id])['lists']->first()->id;
        });

        $this->delete("/inventory/pick-lists/{$pickListId}")->assertRedirect(route('inventory.pickLists.index'));
        $tenant->run(function () use ($pickListId) {
            $this->assertNull(PickList::query()->find($pickListId));
        });
    }

    public function test_generate_pick_list_partially_succeeds_when_one_warehouse_group_fails(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $reservationIds = [];
        $tenant->run(function () use (&$reservationIds) {
            $goodWarehouse = $this->makeWarehouse('Good WH');
            $goodLocation = $this->makeLocation($goodWarehouse);
            $goodProduct = $this->makeProduct('GEN-GOOD');
            $this->seedOnHandStock($goodProduct->id, $goodWarehouse->id, $goodLocation->id, 10);
            $reservationIds[] = app(ReservationService::class)->reserve(['product_id' => $goodProduct->id, 'warehouse_id' => $goodWarehouse->id, 'qty' => 2])->id;

            // Second warehouse: stock split across two bins (5 each, 10 total) is enough for the
            // reservation itself, but no SINGLE bin can cover an unassigned pick of 8 -> that group fails.
            $badWarehouse = $this->makeWarehouse('Bad WH');
            $badLocationA = $this->makeLocation($badWarehouse, 'BAD-A');
            $badLocationB = $this->makeLocation($badWarehouse, 'BAD-B');
            $badProduct = $this->makeProduct('GEN-BAD');
            $this->seedOnHandStock($badProduct->id, $badWarehouse->id, $badLocationA->id, 5);
            $this->seedOnHandStock($badProduct->id, $badWarehouse->id, $badLocationB->id, 5);
            $reservationIds[] = app(ReservationService::class)->reserve(['product_id' => $badProduct->id, 'warehouse_id' => $badWarehouse->id, 'qty' => 8])->id;
        });

        $response = $this->post('/inventory/reservations/generate-pick-list', ['ids' => $reservationIds])->assertRedirect(route('inventory.pickLists.index'));
        $this->assertStringContainsString('Skipped:', session('success'));

        $tenant->run(function () {
            // The good warehouse's pick list was created despite the other group's failure.
            $this->assertSame(1, PickList::query()->count());
        });
    }

    public function test_pack_list_store_validation_rejects_invalid_pick_list_and_unpicked_or_overquantity_lines(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $pendingLineId = null;
        $pickedLineId = null;
        $pickListId = null;
        $tenant->run(function () use (&$pendingLineId, &$pickedLineId, &$pickListId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('PACK-VALID');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 10);

            $r1 = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 3]);
            $r2 = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 2]);

            $result = app(PickListService::class)->generate([$r1->id, $r2->id]);
            $pickList = $result['lists']->first();
            $pickListId = $pickList->id;
            $lines = $pickList->lines;
            $pendingLineId = $lines->firstWhere('reservation_id', $r1->id)->id;

            app(PickListService::class)->pickLine($lines->firstWhere('reservation_id', $r2->id), 2);
            $pickedLineId = $lines->firstWhere('reservation_id', $r2->id)->id;
        });

        $this->post('/inventory/pack-lists', ['pick_list_id' => 999999, 'lines' => [['pick_list_line_id' => $pickedLineId, 'qty' => 1]]])
            ->assertSessionHasErrors(['pick_list_id']);

        // Line still pending (never picked) -> rejected by the service.
        $this->post('/inventory/pack-lists', ['pick_list_id' => $pickListId, 'lines' => [['pick_list_line_id' => $pendingLineId, 'qty' => 1]]])
            ->assertSessionHasErrors(['lines']);

        // Qty greater than what was actually picked.
        $this->post('/inventory/pack-lists', ['pick_list_id' => $pickListId, 'lines' => [['pick_list_line_id' => $pickedLineId, 'qty' => 999]]])
            ->assertSessionHasErrors(['lines']);
    }

    public function test_pack_list_delete_and_update_are_blocked_once_assigned_to_a_shipment(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $packListId = null;
        $pickLineId = null;
        $warehouseId = null;
        $tenant->run(function () use (&$packListId, &$pickLineId, &$warehouseId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('PACK-SHIPPED-GUARD');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5);

            $reservation = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 2]);
            $result = app(PickListService::class)->generate([$reservation->id]);
            $line = $result['lists']->first()->lines->first();
            $pickLineId = $line->id;
            app(PickListService::class)->pickLine($line, 2);

            $packList = app(PackListService::class)->create([
                'pick_list_id' => $result['lists']->first()->id,
                'lines' => [['pick_list_line_id' => $pickLineId, 'qty' => 2]],
            ]);
            $packListId = $packList->id;

            app(ShipmentService::class)->create(['warehouse_id' => $warehouse->id, 'pack_list_ids' => [$packListId]]);
        });

        $this->put("/inventory/pack-lists/{$packListId}", ['lines' => [['pick_list_line_id' => $pickLineId, 'qty' => 1]]])->assertSessionHasErrors(['status']);
        $this->delete("/inventory/pack-lists/{$packListId}")->assertSessionHasErrors(['status']);
    }

    public function test_shipment_store_validation_rejects_invalid_warehouse_and_foreign_or_already_assigned_packages(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $foreignPackListId = null;
        $alreadyAssignedPackListId = null;
        $warehouseId = null;
        $tenant->run(function () use (&$foreignPackListId, &$alreadyAssignedPackListId, &$warehouseId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $otherWarehouse = $this->makeWarehouse('Other WH');
            $location = $this->makeLocation($warehouse);
            $otherLocation = $this->makeLocation($otherWarehouse, 'OTHER-LOC');
            $product = $this->makeProduct('SHIP-VALID');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 10);
            $this->seedOnHandStock($product->id, $otherWarehouse->id, $otherLocation->id, 10);

            $foreignReservation = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $otherWarehouse->id, 'location_id' => $otherLocation->id, 'qty' => 1]);
            $foreignResult = app(PickListService::class)->generate([$foreignReservation->id]);
            $foreignLine = $foreignResult['lists']->first()->lines->first();
            app(PickListService::class)->pickLine($foreignLine, 1);
            $foreignPackListId = app(PackListService::class)->create([
                'pick_list_id' => $foreignResult['lists']->first()->id, 'lines' => [['pick_list_line_id' => $foreignLine->id, 'qty' => 1]],
            ])->id;

            $reservation = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 1]);
            $result = app(PickListService::class)->generate([$reservation->id]);
            $line = $result['lists']->first()->lines->first();
            app(PickListService::class)->pickLine($line, 1);
            $packList = app(PackListService::class)->create([
                'pick_list_id' => $result['lists']->first()->id, 'lines' => [['pick_list_line_id' => $line->id, 'qty' => 1]],
            ]);
            $alreadyAssignedPackListId = $packList->id;
            app(ShipmentService::class)->create(['warehouse_id' => $warehouse->id, 'pack_list_ids' => [$packList->id]]);
        });

        $this->post('/inventory/shipments', ['warehouse_id' => 999999, 'pack_list_ids' => [1]])->assertSessionHasErrors(['warehouse_id']);

        // Belongs to a different warehouse than the shipment header -> not "assignable" (count mismatch).
        $this->post('/inventory/shipments', ['warehouse_id' => $warehouseId, 'pack_list_ids' => [$foreignPackListId]])->assertSessionHasErrors(['pack_list_ids']);

        $this->post('/inventory/shipments', ['warehouse_id' => $warehouseId, 'pack_list_ids' => [$alreadyAssignedPackListId]])->assertSessionHasErrors(['pack_list_ids']);
    }

    public function test_ship_confirm_is_blocked_with_no_packed_lines(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $shipmentId = null;
        $tenant->run(function () use (&$shipmentId) {
            $warehouse = $this->makeWarehouse();
            // Created directly, bypassing the service — no PackList ever attached.
            $shipmentId = Shipment::query()->create(['warehouse_id' => $warehouse->id, 'status' => Shipment::STATUS_PENDING])->id;
        });

        $this->patch("/inventory/shipments/{$shipmentId}/ship-confirm")->assertSessionHasErrors(['pack_lists']);
    }

    public function test_pack_list_index_filters_create_without_a_pick_list_and_successful_update_and_destroy(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $pickListId = null;
        $pickLineId = null;
        $packListId = null;
        $tenant->run(function () use (&$warehouseId, &$pickListId, &$pickLineId, &$packListId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('PACK-INDEX');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5);

            $reservation = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 2]);
            $result = app(PickListService::class)->generate([$reservation->id]);
            $pickListId = $result['lists']->first()->id;
            $line = $result['lists']->first()->lines->first();
            $pickLineId = $line->id;
            app(PickListService::class)->pickLine($line, 2);

            $packList = app(PackListService::class)->create(['pick_list_id' => $pickListId, 'lines' => [['pick_list_line_id' => $pickLineId, 'qty' => 2]]]);
            $packListId = $packList->id;
        });

        $this->get('/inventory/pack-lists')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/PackLists/Index')->has('packLists.data', 1));
        $this->get("/inventory/pack-lists?warehouse_id={$warehouseId}")->assertOk()->assertInertia(fn ($page) => $page->has('packLists.data', 1));
        $this->get('/inventory/pack-lists?status=packed')->assertOk()->assertInertia(fn ($page) => $page->has('packLists.data', 1));
        $this->get('/inventory/pack-lists?sort=created_at&direction=desc&per_page=5')->assertOk();

        // No pick_list_id query param -> the "pick a pick list first" form state, listing eligible ones.
        $this->get('/inventory/pack-lists/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('pickList', null)->has('eligiblePickLists', 1));

        $this->put("/inventory/pack-lists/{$packListId}", [
            'package_type' => PackList::TYPE_CARTON, 'weight' => 3.5, 'lines' => [['pick_list_line_id' => $pickLineId, 'qty' => 2]],
        ])->assertRedirect(route('inventory.packLists.edit', $packListId));
        $tenant->run(function () use ($packListId) {
            $this->assertSame('3.5000', PackList::query()->find($packListId)->weight);
        });

        $this->delete("/inventory/pack-lists/{$packListId}")->assertRedirect(route('inventory.packLists.index'));
        $tenant->run(function () use ($packListId) {
            $this->assertNull(PackList::query()->find($packListId));
        });
    }

    public function test_shipment_index_filters_and_successful_update_and_destroy_while_pending(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $warehouseId = null;
        $packListId = null;
        $secondPackListId = null;
        $shipmentId = null;
        $tenant->run(function () use (&$warehouseId, &$packListId, &$secondPackListId, &$shipmentId) {
            $warehouse = $this->makeWarehouse();
            $warehouseId = $warehouse->id;
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('SHIP-INDEX');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 10);

            $makePackedList = function () use ($product, $warehouse, $location) {
                $reservation = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 1]);
                $result = app(PickListService::class)->generate([$reservation->id]);
                $line = $result['lists']->first()->lines->first();
                app(PickListService::class)->pickLine($line, 1);

                return app(PackListService::class)->create(['pick_list_id' => $result['lists']->first()->id, 'lines' => [['pick_list_line_id' => $line->id, 'qty' => 1]]]);
            };

            $packListId = $makePackedList()->id;
            $secondPackListId = $makePackedList()->id;

            $shipment = app(ShipmentService::class)->create(['warehouse_id' => $warehouse->id, 'pack_list_ids' => [$packListId]]);
            $shipmentId = $shipment->id;
        });

        $this->get('/inventory/shipments')->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Shipments/Index')->has('shipments.data', 1));
        $this->get("/inventory/shipments?warehouse_id={$warehouseId}")->assertOk()->assertInertia(fn ($page) => $page->has('shipments.data', 1));
        $this->get('/inventory/shipments?status=pending')->assertOk()->assertInertia(fn ($page) => $page->has('shipments.data', 1));
        $this->get('/inventory/shipments?sort=ship_date&direction=asc&per_page=5')->assertOk();

        // Successful update while still pending: swap in the second package, dropping the first.
        $this->put("/inventory/shipments/{$shipmentId}", [
            'carrier' => 'FedEx', 'pack_list_ids' => [$secondPackListId],
        ])->assertRedirect(route('inventory.shipments.edit', $shipmentId));

        $tenant->run(function () use ($shipmentId, $packListId, $secondPackListId) {
            $shipment = Shipment::query()->find($shipmentId);
            $this->assertSame('FedEx', $shipment->carrier);
            $this->assertNull(PackList::query()->find($packListId)->shipment_id);
            $this->assertSame($shipmentId, PackList::query()->find($secondPackListId)->shipment_id);
        });

        $this->delete("/inventory/shipments/{$shipmentId}")->assertRedirect(route('inventory.shipments.index'));
        $tenant->run(function () use ($shipmentId, $secondPackListId) {
            $this->assertNull(Shipment::query()->find($shipmentId));
            // Deleting the shipment releases its packages rather than deleting them.
            $this->assertNull(PackList::query()->find($secondPackListId)->shipment_id);
        });
    }

    public function test_generate_pick_list_throws_when_every_warehouse_group_fails(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $reservationIds = [];
        $tenant->run(function () use (&$reservationIds) {
            foreach (['Bad A', 'Bad B'] as $name) {
                $warehouse = $this->makeWarehouse($name);
                $locationA = $this->makeLocation($warehouse, 'A');
                $locationB = $this->makeLocation($warehouse, 'B');
                $product = $this->makeProduct("GEN-ALL-BAD-{$name}");
                $this->seedOnHandStock($product->id, $warehouse->id, $locationA->id, 5);
                $this->seedOnHandStock($product->id, $warehouse->id, $locationB->id, 5);
                $reservationIds[] = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'qty' => 8])->id;
            }
        });

        $this->post('/inventory/reservations/generate-pick-list', ['ids' => $reservationIds])->assertSessionHasErrors(['reservations']);
        $tenant->run(function () {
            $this->assertSame(0, PickList::query()->count());
        });
    }

    public function test_pick_line_validation_rejects_zero_negative_and_overquantity_confirmed_qty(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $pickListId = null;
        $pickLineId = null;
        $tenant->run(function () use (&$pickListId, &$pickLineId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('PICK-QTY');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5);

            $reservation = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 3]);
            $pickList = app(PickListService::class)->generate([$reservation->id])['lists']->first();
            $pickListId = $pickList->id;
            $pickLineId = $pickList->lines->first()->id;
        });

        $this->patch("/inventory/pick-lists/{$pickListId}/lines/{$pickLineId}/pick", ['confirmed_qty' => 0])->assertSessionHasErrors(['confirmed_qty']);
        $this->patch("/inventory/pick-lists/{$pickListId}/lines/{$pickLineId}/pick", ['confirmed_qty' => -1])->assertSessionHasErrors(['confirmed_qty']);
        $this->patch("/inventory/pick-lists/{$pickListId}/lines/{$pickLineId}/pick", ['confirmed_qty' => 999])->assertSessionHasErrors(['confirmed_qty']);
    }

    public function test_pick_line_is_blocked_when_the_underlying_reservation_was_released_elsewhere(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $pickListId = null;
        $pickLineId = null;
        $tenant->run(function () use (&$pickListId, &$pickLineId) {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('PICK-RELEASED');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5);

            $reservation = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 2]);
            $pickList = app(PickListService::class)->generate([$reservation->id])['lists']->first();
            $pickListId = $pickList->id;
            $pickLineId = $pickList->lines->first()->id;

            // Released out from under the pick list before anyone confirms the quantity.
            app(ReservationService::class)->release($reservation);
        });

        $this->patch("/inventory/pick-lists/{$pickListId}/lines/{$pickLineId}/pick", ['confirmed_qty' => 2])
            ->assertSessionHasErrors(['status']);
    }

    /** StorePackListRequest's own 'lines' => 'required|array|min:1' rule makes an empty array
     *  unreachable via HTTP — only a direct service call (e.g. a future internal caller) hits it. */
    public function test_pack_list_service_rejects_empty_lines_and_drops_a_blank_line(): void
    {
        $tenant = $this->loginAsInventoryAdmin();

        $tenant->run(function () {
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $product = $this->makeProduct('PACK-BLANK');
            $this->seedOnHandStock($product->id, $warehouse->id, $location->id, 5);

            $reservation = app(ReservationService::class)->reserve(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty' => 2]);
            $pickList = app(PickListService::class)->generate([$reservation->id])['lists']->first();
            $line = $pickList->lines->first();
            app(PickListService::class)->pickLine($line, 2);

            try {
                app(PackListService::class)->create(['pick_list_id' => $pickList->id, 'lines' => []]);
                $this->fail('Expected a ValidationException for an empty lines array.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines', $e->errors());
            }

            $packList = app(PackListService::class)->create([
                'pick_list_id' => $pickList->id,
                'lines' => [
                    ['pick_list_line_id' => $line->id, 'qty' => 2],
                    ['pick_list_line_id' => null, 'qty' => null],
                ],
            ]);
            $this->assertSame(1, $packList->lines()->count());
        });
    }
}
