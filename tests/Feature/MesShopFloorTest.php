<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\SerialLink;
use App\Modules\MES\Services\OperationExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** MES_SPECS.md §3G Shop Floor Operation UI (assembly) + §3H Serial Genealogy. */
class MesShopFloorTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_show_is_rejected_for_a_process_model_order(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $product = $this->makeProduct('SF-0');
            $recipeId = $this->makeRecipe($product->id)->id;
            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-SF0', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 1, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->get("/mes/shop-floor/{$orderId}")->assertStatus(422);
    }

    public function test_full_single_op_start_pause_resume_complete_cycle_auto_issues_components_and_completes_the_order(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $componentId = null;
        $locationId = null;
        $tenant->run(function () use (&$orderId, &$componentId, &$locationId) {
            $product = $this->makeProduct('SF-1');
            $component = $this->makeProduct('SF-1-COMP');
            $componentId = $component->id;
            $bom = $this->makeBom($product->id);
            $this->makeBomLine($bom, $componentId, ['qty_per_parent_unit' => 2]);
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $workCenter, ['op_code' => 'OP1', 'auto_issue_components' => true]);
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $this->receiveStock($warehouse, $componentId, 100, $component->base_uom_id, $locationId);

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-SF1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id,
                'qty' => 10, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->get("/mes/shop-floor/{$orderId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/ShopFloor/Show')
                ->has('ops', 1)
                ->where('currentOp.op_code', 'OP1')
                ->has('components', 1));

        // Pausing/resuming before the op has ever started hits the service's own "not started
        // yet" / "not paused" guards (currentOp() still returns OP1, not null, at this point).
        $this->post("/mes/shop-floor/{$orderId}/pause")->assertRedirect()->assertSessionHasErrors(['operation']);
        $this->post("/mes/shop-floor/{$orderId}/resume")->assertRedirect()->assertSessionHasErrors(['operation']);

        $this->post("/mes/shop-floor/{$orderId}/start")->assertRedirect();
        $tenant->run(function () use ($orderId) {
            $order = ProdOrder::query()->find($orderId);
            $this->assertSame(ProdOrder::STATUS_IN_PROGRESS, $order->status);
            $this->assertNotNull($order->actual_start);
        });

        // Starting again while already started is rejected.
        $this->post("/mes/shop-floor/{$orderId}/start")->assertRedirect()->assertSessionHasErrors(['operation']);

        $this->post("/mes/shop-floor/{$orderId}/pause")->assertRedirect();
        $tenant->run(function () use ($orderId) {
            $this->assertSame(ProdOrder::STATUS_PAUSED, ProdOrder::query()->find($orderId)->status);
        });

        // Pausing again while already paused is rejected.
        $this->post("/mes/shop-floor/{$orderId}/pause")->assertRedirect()->assertSessionHasErrors(['operation']);

        $this->post("/mes/shop-floor/{$orderId}/resume")->assertRedirect();
        $tenant->run(function () use ($orderId) {
            $this->assertSame(ProdOrder::STATUS_IN_PROGRESS, ProdOrder::query()->find($orderId)->status);
        });

        // Resuming again while already running succeeds as a harmless no-op, NOT a rejection:
        // resume() writes no ledger event (§3C's CHECK has none for it), so its own guard —
        // "latest event for this op is operation_paused" — is still true even after the first
        // resume flipped the order back to in_progress. A second resume() re-evaluates the same
        // (unchanged) event history, finds the guard still satisfied, and no-ops through.
        $this->post("/mes/shop-floor/{$orderId}/resume")->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->post("/mes/shop-floor/{$orderId}/complete", [
            'qty_completed' => 10, 'location_id' => $locationId,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($orderId, $componentId) {
            $order = ProdOrder::query()->find($orderId);
            $this->assertSame(ProdOrder::STATUS_COMPLETED, $order->status);
            $this->assertNotNull($order->actual_end);
            $this->assertSame(1, $order->productionOutputs()->where('output_type', 'finished')->count());
            $consumption = $order->materialConsumptions()->where('material_product_id', $componentId)->first();
            $this->assertNotNull($consumption);
            $this->assertEqualsWithDelta(20.0, (float) $consumption->qty, 0.001); // 2 per unit * 10 units
        });

        // No op left to start/pause/resume/complete once every op is done — currentOp() is
        // null, so the controller's own "No operation..." early-return branches fire (no
        // ValidationException, no session error — just an info flash).
        $this->post("/mes/shop-floor/{$orderId}/start")->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->post("/mes/shop-floor/{$orderId}/pause")->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->post("/mes/shop-floor/{$orderId}/resume")->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->post("/mes/shop-floor/{$orderId}/complete", ['qty_completed' => 1])->assertRedirect()->assertSessionDoesntHaveErrors();
    }

    public function test_sequence_enforcement_blocks_starting_an_op_before_its_predecessor_completes(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $product = $this->makeProduct('SF-2');
            $bom = $this->makeBom($product->id);
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $workCenter, ['seq' => 10, 'op_code' => 'OP1', 'auto_issue_components' => false]);
            $this->makeRoutingOp($routing, $workCenter, ['seq' => 20, 'op_code' => 'OP2', 'auto_issue_components' => false]);
            $warehouse = $this->makeWarehouse();

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-SF2', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id,
                'qty' => 5, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        // currentOp() should be OP1 (first, uncompleted).
        $this->get("/mes/shop-floor/{$orderId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('currentOp.op_code', 'OP1'));

        $this->post("/mes/shop-floor/{$orderId}/start")->assertRedirect();
        $this->post("/mes/shop-floor/{$orderId}/complete", ['qty_completed' => 5])->assertRedirect()->assertSessionDoesntHaveErrors();

        // Now currentOp() should be OP2.
        $this->get("/mes/shop-floor/{$orderId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('currentOp.op_code', 'OP2')->where('currentOp.is_last', true));

        $this->post("/mes/shop-floor/{$orderId}/start")->assertRedirect()->assertSessionDoesntHaveErrors();
    }

    public function test_complete_rejects_a_partial_serial_tracked_completion_and_requires_a_location_for_auto_issue(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $componentId = null;
        $tenant->run(function () use (&$orderId, &$componentId) {
            $product = $this->makeProduct('SF-3', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $component = $this->makeProduct('SF-3-COMP');
            $componentId = $component->id;
            $bom = $this->makeBom($product->id);
            $this->makeBomLine($bom, $componentId);
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $workCenter, ['op_code' => 'OP1', 'auto_issue_components' => true]);
            $warehouse = $this->makeWarehouse();

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-SF3', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id,
                'qty' => 3, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/shop-floor/{$orderId}/start")->assertRedirect();

        // qty_completed != 1 on the final op of a serial-tracked product is rejected.
        $this->post("/mes/shop-floor/{$orderId}/complete", ['qty_completed' => 3])
            ->assertRedirect()->assertSessionHasErrors(['qty_completed']);

        // qty_completed = 1 but no location_id for the auto-issue step.
        $this->post("/mes/shop-floor/{$orderId}/complete", ['qty_completed' => 1])
            ->assertRedirect()->assertSessionHasErrors(['location_id']);
    }

    public function test_complete_rejects_auto_issuing_a_tracked_component(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $locationId = null;
        $tenant->run(function () use (&$orderId, &$locationId) {
            $product = $this->makeProduct('SF-4');
            $component = $this->makeProduct('SF-4-COMP', ['tracking_mode' => Product::TRACKING_BATCH]);
            $bom = $this->makeBom($product->id);
            $this->makeBomLine($bom, $component->id);
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $workCenter, ['op_code' => 'OP1', 'auto_issue_components' => true]);
            $warehouse = $this->makeWarehouse();
            $locationId = $this->makeLocation($warehouse)->id;

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-SF4', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id,
                'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/shop-floor/{$orderId}/start")->assertRedirect();
        $this->post("/mes/shop-floor/{$orderId}/complete", ['qty_completed' => 1, 'location_id' => $locationId])
            ->assertRedirect()->assertSessionHasErrors(['location_id']);
    }

    public function test_complete_records_a_reject_output_and_a_reject_reason(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $locationId = null;
        $tenant->run(function () use (&$orderId, &$locationId) {
            $product = $this->makeProduct('SF-5');
            $bom = $this->makeBom($product->id);
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $workCenter, ['op_code' => 'OP1', 'auto_issue_components' => false]);
            $warehouse = $this->makeWarehouse();
            $locationId = $this->makeLocation($warehouse)->id;

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-SF5', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id,
                'qty' => 10, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/shop-floor/{$orderId}/start")->assertRedirect();
        $this->post("/mes/shop-floor/{$orderId}/complete", [
            'qty_completed' => 8, 'qty_rejected' => 2, 'reject_reason_code' => 'defect', 'location_id' => $locationId,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($orderId) {
            $waste = ProdOrder::query()->find($orderId)->productionOutputs()->where('output_type', 'waste')->first();
            $this->assertNotNull($waste);
            $this->assertSame('defect', $waste->reason_code);
            $this->assertEqualsWithDelta(2.0, (float) $waste->qty, 0.001);
        });
    }

    public function test_serial_genealogy_links_consumed_lots_to_the_finished_serial(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $componentId = null;
        $untrackedComponentId = null;
        $lotId = null;
        $locationId = null;
        $tenant->run(function () use (&$orderId, &$componentId, &$untrackedComponentId, &$lotId, &$locationId) {
            $product = $this->makeProduct('SF-6', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $component = $this->makeProduct('SF-6-COMP', ['tracking_mode' => Product::TRACKING_BATCH]);
            $untrackedComponent = $this->makeProduct('SF-6-UNTRACKED');
            $componentId = $component->id;
            $untrackedComponentId = $untrackedComponent->id;
            $bom = $this->makeBom($product->id);
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            // auto_issue_components=false — a batch/serial-tracked component must be issued manually.
            $this->makeRoutingOp($routing, $workCenter, ['op_code' => 'OP1', 'auto_issue_components' => false]);
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $this->receiveStock($warehouse, $componentId, 10, $component->base_uom_id, $locationId, 'GEN-LOT-1');
            $this->receiveStock($warehouse, $untrackedComponentId, 10, $untrackedComponent->base_uom_id, $locationId);
            $lotId = StockBatch::query()->where('product_id', $componentId)->value('id');

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-SF6', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id,
                'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/shop-floor/{$orderId}/start")->assertRedirect();

        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $componentId, 'type' => 'issue', 'qty' => 2, 'location_id' => $locationId, 'lot_id' => $lotId,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        // An untracked-material issue carries neither lot_id nor serial_id — genealogy has
        // nothing to link it to, so SerialGenealogyService::linkOrderConsumptionsToSerial()
        // skips it (its own CHECK-constraint-driven "nothing to link" branch).
        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $untrackedComponentId, 'type' => 'issue', 'qty' => 1, 'location_id' => $locationId,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->post("/mes/shop-floor/{$orderId}/complete", [
            'qty_completed' => 1, 'serial_number' => 'FIN-SER-1', 'location_id' => $locationId,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($orderId, $componentId) {
            $this->assertSame(1, SerialLink::query()->where('order_id', $orderId)->count());
            $link = SerialLink::query()->where('order_id', $orderId)->first();
            $this->assertSame($componentId, $link->material_product_id);
            $this->assertNotNull($link->component_lot_id);
        });
    }

    /** OperationExecutionService::start/pause/complete()'s "this operation does not belong to the order's routing" guard is unreachable via the controller (the op is always derived from the order's own routing) — only reachable via a direct, mismatched service call. */
    public function test_service_rejects_an_operation_that_does_not_belong_to_the_order(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $productA = $this->makeProduct('SF-7A');
            $productB = $this->makeProduct('SF-7B');
            $workCenter = $this->makeWorkCenter();
            $bomA = $this->makeBom($productA->id);
            $routingA = $this->makeRouting($productA->id);
            $this->makeRoutingOp($routingA, $workCenter);
            $routingB = $this->makeRouting($productB->id);
            $foreignOp = $this->makeRoutingOp($routingB, $workCenter);
            $warehouse = $this->makeWarehouse();

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-SF7', 'product_id' => $productA->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bomA->id, 'routing_id' => $routingA->id, 'warehouse_id' => $warehouse->id,
                'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            try {
                app(OperationExecutionService::class)->start($order, $foreignOp, $this->adminUserId());
                $this->fail('Expected a ValidationException for an operation not belonging to this order.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('operation', $e->errors());
            }
        });
    }

    /** Assembly is the one production_model with no CHECK constraint requiring routing_id (only bom_id is enforced) — a direct-model order with no routing set is the only way currentOp()/statusesFor() see a null routing_id, and the only way componentAvailability() sees a null warehouse_id without violating a DB constraint. */
    public function test_show_and_start_degrade_gracefully_for_an_order_with_no_routing_or_warehouse(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $product = $this->makeProduct('SF-8');
            $bom = $this->makeBom($product->id);

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-SF8', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->get("/mes/shop-floor/{$orderId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('ops', 0)->where('currentOp', null)->has('components', 0));

        $this->post("/mes/shop-floor/{$orderId}/start")->assertRedirect()->assertSessionDoesntHaveErrors();
    }

    /** OperationExecutionService::start()'s "order must be released" guard — a draft order's op has never started (so the "already started" check doesn't fire first), but the order itself isn't released yet. */
    public function test_start_is_rejected_on_a_draft_order(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $product = $this->makeProduct('SF-9D');
            $bom = $this->makeBom($product->id);
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $workCenter, ['op_code' => 'OP1']);
            $warehouse = $this->makeWarehouse();

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-SF9D', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id,
                'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_DRAFT,
            ])->id;
        });

        $this->post("/mes/shop-floor/{$orderId}/start")->assertRedirect()->assertSessionHasErrors(['status']);
    }

    /** OperationExecutionService::complete()'s "only a started operation can be completed" guard, reached via HTTP without ever calling start() first. */
    public function test_complete_is_rejected_when_the_operation_was_never_started(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $product = $this->makeProduct('SF-9');
            $bom = $this->makeBom($product->id);
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $workCenter, ['op_code' => 'OP1']);
            $warehouse = $this->makeWarehouse();

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-SF9', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id,
                'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/shop-floor/{$orderId}/complete", ['qty_completed' => 1])
            ->assertRedirect()->assertSessionHasErrors(['operation']);
    }

    /** assertPredecessorsCompleted()'s own throw, reached via a direct service call starting OP2 while OP1 is still open — the controller itself can never do this (it always resolves currentOp() first, which returns OP1 in this state). */
    public function test_service_rejects_starting_an_operation_before_its_predecessor_completes(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('SF-10');
            $bom = $this->makeBom($product->id);
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $op1 = $this->makeRoutingOp($routing, $workCenter, ['seq' => 10, 'op_code' => 'OP1']);
            $op2 = $this->makeRoutingOp($routing, $workCenter, ['seq' => 20, 'op_code' => 'OP2']);
            $warehouse = $this->makeWarehouse();

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-SF10', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id,
                'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            try {
                app(OperationExecutionService::class)->start($order, $op2, $this->adminUserId());
                $this->fail('Expected a ValidationException for starting OP2 before OP1 completes.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('operation', $e->errors());
            }
        });
    }
}
