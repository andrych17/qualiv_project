<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\MES\Models\BatchIngredient;
use App\Modules\MES\Models\BatchParameterReading;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\MaterialConsumption;
use App\Modules\MES\Models\MesBatch;
use App\Modules\MES\Models\ProcessParameter;
use App\Modules\MES\Models\ProcessPhase;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Models\SerialLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Phase 2 (§3G/§3H/§3I/§3J) child->parent Eloquent relations no controller's own eager-load happens to touch. */
class MesPhase2FacadeAndModelTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_material_consumption_and_production_output_relations(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('REL2-1', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $material = $this->makeProduct('REL2-1-MAT', ['tracking_mode' => Product::TRACKING_BATCH]);
            $serialMaterial = $this->makeProduct('REL2-1-SERMAT', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $recipe = $this->makeRecipe($product->id);
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $this->receiveStock($warehouse, $material->id, 10, $material->base_uom_id, $location->id, 'REL-LOT-1');
            $lot = StockBatch::query()->where('product_id', $material->id)->first();

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-REL2-1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            $consumption = MaterialConsumption::query()->create([
                'order_id' => $order->id, 'material_product_id' => $material->id, 'lot_id' => $lot->id,
                'qty' => 1, 'type' => MaterialConsumption::TYPE_ISSUE, 'created_at' => now(),
            ]);
            $this->assertSame($order->id, $consumption->order->id);
            $this->assertSame($material->id, $consumption->material->id);
            $this->assertSame($lot->id, $consumption->lot->id);

            $this->receiveStock($warehouse, $serialMaterial->id, 1, $serialMaterial->base_uom_id, $location->id, null, 'REL-SER-1');
            $serial = StockSerial::query()->where('product_id', $serialMaterial->id)->first();
            $consumptionWithSerial = MaterialConsumption::query()->create([
                'order_id' => $order->id, 'material_product_id' => $serialMaterial->id, 'serial_id' => $serial->id,
                'qty' => 1, 'type' => MaterialConsumption::TYPE_ISSUE, 'created_at' => now(),
            ]);
            $this->assertSame($serial->id, $consumptionWithSerial->serial->id);

            $output = ProductionOutput::query()->create([
                'order_id' => $order->id, 'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $product->id,
                'qty' => 1, 'lot_id' => $lot->id, 'serial_id' => $serial->id, 'created_at' => now(),
            ]);
            $this->assertSame($order->id, $output->order->id);
            $this->assertSame($product->id, $output->product->id);
            $this->assertSame($lot->id, $output->lot->id);
            $this->assertSame($serial->id, $output->serial->id);

            $link = SerialLink::query()->create([
                'serial_id' => $serial->id, 'component_lot_id' => $lot->id, 'material_product_id' => $material->id,
                'order_id' => $order->id, 'created_at' => now(),
            ]);
            $this->assertSame($serial->id, $link->serial->id);
            $this->assertSame($lot->id, $link->componentLot->id);
            $this->assertSame($material->id, $link->material->id);
            $this->assertSame($order->id, $link->order->id);

            $link2 = SerialLink::query()->create([
                'component_serial_id' => $serial->id, 'material_product_id' => $material->id,
                'order_id' => $order->id, 'created_at' => now(),
            ]);
            $this->assertSame($serial->id, $link2->componentSerial->id);
        });
    }

    public function test_mes_batch_and_children_relations(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('REL2-2');
            $rawMaterial = $this->makeProduct('REL2-2-RAW');
            $recipe = $this->makeRecipe($product->id, ['batch_size' => 10]);
            $workCenter = $this->makeWorkCenter();
            $machine = $this->makeMachine($workCenter);
            $phase = ProcessPhase::query()->create(['recipe_id' => $recipe->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id]);
            $parameter = ProcessParameter::query()->create(['process_phase_id' => $phase->id, 'parameter_code' => 'TEMP', 'min_value' => 1, 'max_value' => 2]);

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-REL2-2', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 10, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            $batch = MesBatch::query()->create([
                'order_id' => $order->id, 'batch_number' => 'B-REL2-2', 'recipe_id' => $recipe->id,
                'status' => MesBatch::STATUS_DRAFT, 'planned_qty' => 10,
            ]);
            $this->assertSame($order->id, $batch->order->id);
            $this->assertSame($recipe->id, $batch->recipe->id);

            $ingredient = BatchIngredient::query()->create([
                'batch_id' => $batch->id, 'raw_material_product_id' => $rawMaterial->id, 'resolved_qty' => 1,
            ]);
            $this->assertSame($batch->id, $ingredient->batch->id);
            $this->assertSame($rawMaterial->id, $ingredient->rawMaterial->id);
            $this->assertSame(1, $batch->ingredients()->count());

            $batchPhase = BatchPhase::query()->create([
                'batch_id' => $batch->id, 'process_phase_id' => $phase->id, 'seq' => 10,
                'status' => BatchPhase::STATUS_RUNNING, 'machine_id' => $machine->id,
            ]);
            $this->assertSame($batch->id, $batchPhase->batch->id);
            $this->assertSame($phase->id, $batchPhase->processPhase->id);
            $this->assertSame($machine->id, $batchPhase->machine->id);
            $this->assertSame(1, $batch->phases()->count());

            $reading = BatchParameterReading::query()->create([
                'batch_phase_id' => $batchPhase->id, 'process_parameter_id' => $parameter->id, 'value' => 1.5,
                'recorded_at' => now(), 'recorded_by' => $this->adminUserId(), 'machine_id' => $machine->id,
            ]);
            $this->assertSame($batchPhase->id, $reading->phase->id);
            $this->assertSame($parameter->id, $reading->parameter->id);
            $this->assertSame($this->adminUserId(), $reading->recordedBy->id);
            $this->assertSame($machine->id, $reading->machine->id);
            $this->assertSame(1, $batchPhase->readings()->count());
        });
    }
}
