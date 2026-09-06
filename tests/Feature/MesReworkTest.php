<?php

namespace Tests\Feature;

use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Services\ReworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** MES_SPECS.md §3N Scrap & Rework — ReworkController/ReworkService, plus the rework-aware branches in OperationExecutionService (currentOp's start-seq skip, startSeqFor's two call paths) and ProdOrderController::show()'s rework-link/is_rework_order display. */
class MesReworkTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_sending_a_flagged_waste_output_to_rework_creates_and_releases_a_child_order_that_executes_from_the_rework_op(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $product = $this->makeProduct('RWK-1');
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $this->makeBom($product->id);
            $routing = $this->makeRouting($product->id);
            $op1 = $this->makeRoutingOp($routing, $this->makeWorkCenter('WC-RWK1'), ['seq' => 10, 'op_code' => 'OP1', 'auto_issue_components' => false]);
            $op2 = $this->makeRoutingOp($routing, $this->makeWorkCenter('WC-RWK2'), ['seq' => 20, 'op_code' => 'OP2', 'auto_issue_components' => false, 'is_rework_destination' => true]);

            $ids = ['product' => $product->id, 'warehouse' => $warehouse->id, 'location' => $location->id, 'op1' => $op1->id, 'op2' => $op2->id];
        });

        $storeResponse = $this->post('/mes/prod-orders', [
            'product_id' => $ids['product'], 'production_model' => ProdOrder::MODEL_ASSEMBLY,
            'qty' => 1, 'uom_code' => 'PCS', 'warehouse_id' => $ids['warehouse'],
        ]);

        $parentId = null;
        $tenant->run(function () use (&$parentId) {
            $parentId = ProdOrder::query()->where('order_number', 'like', 'WO-%')->latest('id')->value('id');
        });

        $this->post("/mes/prod-orders/{$parentId}/release");
        $this->post("/mes/shop-floor/{$parentId}/start");
        $this->post("/mes/shop-floor/{$parentId}/complete", ['qty_completed' => 1]);

        $this->post("/mes/prod-orders/{$parentId}/production-outputs", [
            'output_type' => 'waste', 'product_id' => $ids['product'], 'qty' => 1,
            'location_id' => $ids['location'], 'reason_code' => 'defect', 'disposition' => 'rework',
        ]);

        $outputId = null;
        $tenant->run(function () use (&$outputId, $parentId) {
            $outputId = ProductionOutput::query()->where('order_id', $parentId)->where('disposition', 'rework')->value('id');
        });
        $this->assertNotNull($outputId);

        $reworkResponse = $this->post("/mes/production-outputs/{$outputId}/rework");
        $reworkResponse->assertSessionHasNoErrors();

        $childId = null;
        $tenant->run(function () use (&$childId, $parentId) {
            $childId = ProdOrder::query()->where('parent_order_id', $parentId)->value('id');
        });
        $this->assertNotNull($childId);

        $tenant->run(function () use ($childId, $ids) {
            $child = ProdOrder::query()->find($childId);
            $this->assertSame(ProdOrder::STATUS_RELEASED, $child->status);
            $this->assertSame($ids['product'], $child->product_id);
        });

        $this->get("/mes/prod-orders/{$parentId}")->assertInertia(fn (Assert $page) => $page
            ->where('order.production_outputs.0.rework_order.id', $childId)
        );

        $this->get("/mes/prod-orders/{$childId}")->assertInertia(fn (Assert $page) => $page
            ->where('order.is_rework_order', true)
            ->where('order.parent_order.id', $parentId)
        );

        // currentOp() must skip op1 (seq 10 < the rework-flagged op2's seq 20) and land on op2.
        $this->get("/mes/shop-floor/{$childId}")->assertInertia(fn (Assert $page) => $page
            ->where('currentOp.id', $ids['op2'])
        );

        // start() re-derives currentOp and calls assertPredecessorsCompleted(), which resolves
        // startSeqFor() via the $routingId path (no predecessors within [startSeq, op2->seq) so it succeeds).
        $this->post("/mes/shop-floor/{$childId}/start")->assertSessionHasNoErrors();

        // op2 is this routing's last op — completing it posts finished output and closes the child order.
        $this->post("/mes/shop-floor/{$childId}/complete", [
            'qty_completed' => 1, 'location_id' => $ids['location'],
        ])->assertSessionHasNoErrors();

        $tenant->run(function () use ($childId) {
            $child = ProdOrder::query()->find($childId);
            $this->assertSame(ProdOrder::STATUS_COMPLETED, $child->status);
        });
    }

    public function test_send_to_rework_rejects_an_output_that_is_not_a_rework_flagged_waste_row(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $outputId = null;
        $tenant->run(function () use (&$outputId) {
            $product = $this->makeProduct('RWK-2');
            $warehouse = $this->makeWarehouse();

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-RWK-2', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $this->makeBom($product->id)->id,
                'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            $output = ProductionOutput::query()->create([
                'order_id' => $order->id, 'output_type' => ProductionOutput::TYPE_FINISHED,
                'product_id' => $product->id, 'qty' => 1, 'created_at' => now(),
            ]);

            $outputId = $output->id;
        });

        $this->post("/mes/production-outputs/{$outputId}/rework")
            ->assertSessionHasErrors('disposition');
    }

    public function test_send_to_rework_rejects_an_output_already_sent_to_rework(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $outputId = null;
        $tenant->run(function () use (&$outputId) {
            $product = $this->makeProduct('RWK-3');
            $warehouse = $this->makeWarehouse();
            $routing = $this->makeRouting($product->id);
            $op = $this->makeRoutingOp($routing, $this->makeWorkCenter('WC-RWK4'), ['seq' => 10, 'auto_issue_components' => false, 'is_rework_destination' => true]);

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-RWK-3', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $this->makeBom($product->id)->id, 'routing_id' => $routing->id,
                'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            $output = ProductionOutput::query()->create([
                'order_id' => $order->id, 'output_type' => ProductionOutput::TYPE_WASTE, 'disposition' => ProductionOutput::DISPOSITION_REWORK,
                'product_id' => $product->id, 'qty' => 1, 'reason_code' => 'defect', 'created_at' => now(),
            ]);

            ProdOrder::query()->create([
                'order_number' => 'WO-RWK-3-CHILD', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $order->bom_id, 'routing_id' => $order->routing_id, 'warehouse_id' => $warehouse->id,
                'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
                'parent_order_id' => $order->id, 'source_type' => ReworkService::SOURCE_TYPE, 'source_id' => $output->id,
            ]);

            $outputId = $output->id;
        });

        $this->post("/mes/production-outputs/{$outputId}/rework")
            ->assertSessionHasErrors('output');
    }

    public function test_send_to_rework_rejects_a_process_model_order(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $outputId = null;
        $tenant->run(function () use (&$outputId) {
            $product = $this->makeProduct('RWK-4');
            $warehouse = $this->makeWarehouse();
            $recipe = $this->makeRecipe($product->id);

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-RWK-4', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            $output = ProductionOutput::query()->create([
                'order_id' => $order->id, 'output_type' => ProductionOutput::TYPE_WASTE, 'disposition' => ProductionOutput::DISPOSITION_REWORK,
                'product_id' => $product->id, 'qty' => 1, 'reason_code' => 'defect', 'created_at' => now(),
            ]);

            $outputId = $output->id;
        });

        $this->post("/mes/production-outputs/{$outputId}/rework")
            ->assertSessionHasErrors('production_model');
    }

    public function test_send_to_rework_rejects_a_routing_with_no_rework_destination_op(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $outputId = null;
        $tenant->run(function () use (&$outputId) {
            $product = $this->makeProduct('RWK-5');
            $warehouse = $this->makeWarehouse();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $this->makeWorkCenter('WC-RWK5'), ['seq' => 10, 'auto_issue_components' => false]);

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-RWK-5', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $this->makeBom($product->id)->id, 'routing_id' => $routing->id,
                'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            $output = ProductionOutput::query()->create([
                'order_id' => $order->id, 'output_type' => ProductionOutput::TYPE_WASTE, 'disposition' => ProductionOutput::DISPOSITION_REWORK,
                'product_id' => $product->id, 'qty' => 1, 'reason_code' => 'defect', 'created_at' => now(),
            ]);

            $outputId = $output->id;
        });

        $this->post("/mes/production-outputs/{$outputId}/rework")
            ->assertSessionHasErrors('routing_id');
    }
}
