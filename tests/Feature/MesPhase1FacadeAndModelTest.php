<?php

namespace Tests\Feature;

use App\Modules\MES\Models\ProcessParameter;
use App\Modules\MES\Models\ProcessPhase;
use App\Modules\MES\Models\ProdOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Phase 1 (§3D/§3E/§3F/§3A) child->parent Eloquent relations no controller's own eager-load happens to touch. */
class MesPhase1FacadeAndModelTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_equipment_hierarchy_relations(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $workCenter = $this->makeWorkCenter();
            $this->assertSame(0, $workCenter->machines()->count());

            $machine = $this->makeMachine($workCenter);
            $this->assertSame($workCenter->id, $machine->workCenter->id);

            $station = $this->makeStation('ST-REL', ['work_center_id' => $workCenter->id, 'machine_id' => $machine->id]);
            $this->assertSame($workCenter->id, $station->workCenter->id);
            $this->assertSame($machine->id, $station->machine->id);
        });
    }

    public function test_routing_relations(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('REL-1');
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $op = $this->makeRoutingOp($routing, $workCenter);

            $this->assertSame($product->id, $routing->product->id);
            $this->assertSame(1, $routing->ops()->count());
            $this->assertSame($routing->id, $op->routing->id);
            $this->assertSame($workCenter->id, $op->workCenter->id);
        });
    }

    public function test_process_phase_and_parameter_relations(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('REL-2');
            $recipe = $this->makeRecipe($product->id);
            $workCenter = $this->makeWorkCenter();

            $phase = ProcessPhase::query()->create([
                'recipe_id' => $recipe->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id,
            ]);
            $parameter = ProcessParameter::query()->create([
                'process_phase_id' => $phase->id, 'parameter_code' => 'TEMP', 'min_value' => 1, 'max_value' => 2,
            ]);

            $this->assertSame($recipe->id, $phase->recipe->id);
            $this->assertSame($workCenter->id, $phase->workCenter->id);
            $this->assertSame(1, $phase->parameters()->count());
            $this->assertSame($phase->id, $parameter->phase->id);
        });
    }

    public function test_prod_order_relations(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('REL-3');
            $bom = $this->makeBom($product->id);
            $recipe = $this->makeRecipe($product->id, ['version' => 2]);
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $workCenter);
            $warehouse = $this->makeWarehouse();

            $parent = ProdOrder::query()->create([
                'order_number' => 'WO-REL-P', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id,
                'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_DRAFT,
            ]);
            $child = ProdOrder::query()->create([
                'order_number' => 'WO-REL-C', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'parent_order_id' => $parent->id,
                'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_DRAFT,
            ]);

            $this->assertSame($bom->id, $parent->bom->id);
            $this->assertSame($routing->id, $parent->routing->id);
            $this->assertSame($warehouse->id, $parent->warehouse->id);
            $this->assertSame($parent->id, $child->parentOrder->id);
            $this->assertSame(0, $parent->events()->count());
            $this->assertSame(0, $parent->materialConsumptions()->count());
            $this->assertSame(0, $parent->productionOutputs()->count());
            $this->assertSame(0, $parent->serialLinks()->count());
            $this->assertSame(0, $parent->batches()->count());

            // recipe() belongs-to — exercised on a process-model order (assembly orders never set recipe_id).
            $processOrder = ProdOrder::query()->create([
                'order_number' => 'WO-REL-PR', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 1, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_DRAFT,
            ]);
            $this->assertSame($recipe->id, $processOrder->recipe->id);
        });
    }
}
