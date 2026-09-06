<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockSerial;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\MesBatch;
use App\Modules\MES\Models\ProcessPhase;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Models\QcCharacteristic;
use App\Modules\MES\Models\QcHold;
use App\Modules\MES\Models\QcInspectionPlan;
use App\Modules\MES\Models\QcResult;
use App\Modules\MES\Models\QcSample;
use App\Modules\MES\Services\QcPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * MES_SPECS.md §3L Quality Control — QcPlanController/QcPlanService (plan+characteristics CRUD),
 * QcSampleController/QcInspectionService (sample recording, auto-hold on a finished-goods fail),
 * QcHoldController (release), plus ProdOrderController::show()'s QC panel display closures.
 */
class MesQcTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_qc_plan_crud(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $productId = $this->makeProduct('QC-1')->id;
        });

        $this->post('/mes/qc-plans', [
            'product_id' => $productId, 'name' => 'Incoming Inspection',
            'characteristics' => [
                ['characteristic_name' => 'Diameter', 'spec_type' => 'numeric', 'target_value' => 10, 'min_value' => 9.5, 'max_value' => 10.5, 'uom_code' => 'MM'],
                ['characteristic_name' => 'Visual', 'spec_type' => 'pass_fail'],
            ],
        ])->assertSessionHasNoErrors();

        $planId = null;
        $tenant->run(function () use (&$planId, $productId) {
            $planId = QcInspectionPlan::query()->where('product_id', $productId)->value('id');
            $this->assertSame(2, QcCharacteristic::query()->where('plan_id', $planId)->count());
        });

        $this->get('/mes/qc-plans?search=Incoming')->assertInertia(fn (Assert $page) => $page
            ->where('plans.data.0.name', 'Incoming Inspection')
            ->where('plans.data.0.characteristic_count', 2)
        );

        $this->get('/mes/qc-plans/create')->assertOk();

        $this->get("/mes/qc-plans/{$planId}/edit")->assertInertia(fn (Assert $page) => $page
            ->where('plan.name', 'Incoming Inspection')
            ->has('plan.characteristics', 2)
        );

        $this->put("/mes/qc-plans/{$planId}", [
            'product_id' => $productId, 'name' => 'Incoming Inspection v2',
            'characteristics' => [
                ['characteristic_name' => 'Weight', 'spec_type' => 'numeric', 'min_value' => 1, 'max_value' => 2],
            ],
        ])->assertSessionHasNoErrors();

        $tenant->run(function () use ($planId) {
            $this->assertSame('Incoming Inspection v2', QcInspectionPlan::query()->find($planId)->name);
            $this->assertSame(1, QcCharacteristic::query()->where('plan_id', $planId)->count());
        });

        $this->delete("/mes/qc-plans/{$planId}")->assertSessionHasNoErrors();
        $tenant->run(function () use ($planId) {
            $this->assertNull(QcInspectionPlan::query()->find($planId));
        });
    }

    public function test_qc_plan_store_rejects_an_invalid_product(): void
    {
        $this->loginAsMesAdmin();

        $this->post('/mes/qc-plans', [
            'product_id' => 999999, 'name' => 'Bad Plan',
            'characteristics' => [['characteristic_name' => 'X', 'spec_type' => 'numeric']],
        ])->assertSessionHasErrors('product_id');
    }

    public function test_qc_plan_update_rejects_an_invalid_product(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $planId = null;
        $tenant->run(function () use (&$planId) {
            $planId = QcInspectionPlan::query()->create(['name' => 'Update Target Plan'])->id;
        });

        $this->put("/mes/qc-plans/{$planId}", [
            'product_id' => 999999, 'name' => 'Update Target Plan',
            'characteristics' => [['characteristic_name' => 'X', 'spec_type' => 'numeric']],
        ])->assertSessionHasErrors('product_id');
    }

    /** FormRequest already requires characteristic_name, so QcPlanService::syncCharacteristics()'s own blank-name skip is only reachable via a direct service call — same convention as ProcessPhaseService's equivalent guard in Phase 1. */
    public function test_sync_characteristics_skips_a_blank_name_via_direct_service_call(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $plan = app(QcPlanService::class)->create([
                'name' => 'Direct Plan',
                'characteristics' => [
                    ['characteristic_name' => '', 'spec_type' => 'numeric'],
                    ['characteristic_name' => 'Kept', 'spec_type' => 'numeric'],
                ],
            ]);

            $this->assertSame(1, $plan->characteristics()->count());
            $this->assertSame('Kept', $plan->characteristics()->first()->characteristic_name);
        });
    }

    public function test_recording_a_qc_sample_against_an_order_with_a_fail_result_holds_the_tracked_output_lot(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $product = $this->makeProduct('QC-2', ['tracking_mode' => Product::TRACKING_BATCH]);
            $warehouse = $this->makeWarehouse();
            $recipe = $this->makeRecipe($product->id);

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-QC-2', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            $this->receiveStock($warehouse, $product->id, 1, $product->base_uom_id, $this->makeLocation($warehouse)->id, 'QC-LOT-1');
            $lot = StockBatch::query()->where('product_id', $product->id)->first();

            $output = ProductionOutput::query()->create([
                'order_id' => $order->id, 'output_type' => ProductionOutput::TYPE_FINISHED,
                'product_id' => $product->id, 'qty' => 1, 'lot_id' => $lot->id, 'created_at' => now(),
            ]);

            $plan = QcInspectionPlan::query()->create(['product_id' => $product->id, 'name' => 'FG Check']);
            $characteristic = QcCharacteristic::query()->create(['plan_id' => $plan->id, 'characteristic_name' => 'Weight', 'spec_type' => 'numeric']);

            $ids = ['order' => $order->id, 'output' => $output->id, 'characteristic' => $characteristic->id, 'lot' => $lot->id];
        });

        $this->post('/mes/qc-samples', [
            'order_id' => $ids['order'], 'output_id' => $ids['output'],
            'results' => [['characteristic_id' => $ids['characteristic'], 'actual_value' => 99, 'result' => 'fail']],
        ])->assertSessionHasNoErrors();

        $tenant->run(function () use ($ids) {
            $sample = QcSample::query()->where('order_id', $ids['order'])->first();
            $this->assertSame(1, $sample->results()->count());

            $hold = QcHold::query()->where('subject_type', 'inventory.stock_batches')->where('subject_id', $ids['lot'])->first();
            $this->assertNotNull($hold);
            $this->assertSame(QcHold::STATUS_OPEN, $hold->status);
        });
    }

    /** holdOutput()'s subject-resolution `match` has a lot_id/serial_id/neither arm — the lot_id case is covered above; this exercises the serial_id arm. */
    public function test_recording_a_qc_sample_fail_against_a_serial_tracked_output_holds_the_serial(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $product = $this->makeProduct('QC-8', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $warehouse = $this->makeWarehouse();
            $recipe = $this->makeRecipe($product->id);

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-QC-8', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            $this->receiveStock($warehouse, $product->id, 1, $product->base_uom_id, $this->makeLocation($warehouse)->id, null, 'QC-SER-1');
            $serial = StockSerial::query()->where('product_id', $product->id)->first();

            $output = ProductionOutput::query()->create([
                'order_id' => $order->id, 'output_type' => ProductionOutput::TYPE_FINISHED,
                'product_id' => $product->id, 'qty' => 1, 'serial_id' => $serial->id, 'created_at' => now(),
            ]);

            $plan = QcInspectionPlan::query()->create(['product_id' => $product->id, 'name' => 'FG Serial Check']);
            $characteristic = QcCharacteristic::query()->create(['plan_id' => $plan->id, 'characteristic_name' => 'Visual', 'spec_type' => 'pass_fail']);

            $ids = ['order' => $order->id, 'output' => $output->id, 'characteristic' => $characteristic->id, 'serial' => $serial->id];
        });

        $this->post('/mes/qc-samples', [
            'order_id' => $ids['order'], 'output_id' => $ids['output'],
            'results' => [['characteristic_id' => $ids['characteristic'], 'result' => 'fail']],
        ])->assertSessionHasNoErrors();

        $tenant->run(function () use ($ids) {
            $hold = QcHold::query()->where('subject_type', 'inventory.stock_serials')->where('subject_id', $ids['serial'])->first();
            $this->assertNotNull($hold);
        });
    }

    public function test_recording_a_passing_sample_against_a_batch_phase_creates_no_hold(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $product = $this->makeProduct('QC-3');
            $recipe = $this->makeRecipe($product->id, ['batch_size' => 10]);
            $workCenter = $this->makeWorkCenter('WC-QC3');
            $phase = ProcessPhase::query()->create(['recipe_id' => $recipe->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id]);

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-QC-3', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 10, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            $batch = MesBatch::query()->create(['order_id' => $order->id, 'batch_number' => 'B-QC-3', 'recipe_id' => $recipe->id, 'status' => MesBatch::STATUS_RUNNING, 'planned_qty' => 10]);
            $batchPhase = BatchPhase::query()->create(['batch_id' => $batch->id, 'process_phase_id' => $phase->id, 'seq' => 10, 'status' => BatchPhase::STATUS_RUNNING]);

            $plan = QcInspectionPlan::query()->create(['product_id' => $product->id, 'name' => 'In-Process Check']);
            $characteristic = QcCharacteristic::query()->create(['plan_id' => $plan->id, 'characteristic_name' => 'Temp', 'spec_type' => 'numeric']);

            $ids = ['batchPhase' => $batchPhase->id, 'characteristic' => $characteristic->id];
        });

        $this->post('/mes/qc-samples', [
            'batch_phase_id' => $ids['batchPhase'],
            'results' => [['characteristic_id' => $ids['characteristic'], 'actual_value' => 1.5, 'result' => 'pass']],
        ])->assertSessionHasNoErrors();

        $tenant->run(function () use ($ids) {
            $sample = QcSample::query()->where('batch_phase_id', $ids['batchPhase'])->first();
            $this->assertNotNull($sample);
            $this->assertSame(0, QcHold::query()->count());
        });
    }

    /** A fail result naming an output_id that doesn't exist reaches QcInspectionService::holdOutput()'s "output not found" early return — reachable via HTTP because the FormRequest only cross-checks output_id against order_id, not against batch_phase_id. */
    public function test_recording_a_fail_with_an_unresolvable_output_id_is_a_silent_no_op_for_the_hold(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $product = $this->makeProduct('QC-4');
            $recipe = $this->makeRecipe($product->id, ['batch_size' => 10]);
            $workCenter = $this->makeWorkCenter('WC-QC4');
            $phase = ProcessPhase::query()->create(['recipe_id' => $recipe->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id]);
            $order = ProdOrder::query()->create([
                'order_number' => 'WO-QC-4', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 10, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            $batch = MesBatch::query()->create(['order_id' => $order->id, 'batch_number' => 'B-QC-4', 'recipe_id' => $recipe->id, 'status' => MesBatch::STATUS_RUNNING, 'planned_qty' => 10]);
            $batchPhase = BatchPhase::query()->create(['batch_id' => $batch->id, 'process_phase_id' => $phase->id, 'seq' => 10, 'status' => BatchPhase::STATUS_RUNNING]);
            $plan = QcInspectionPlan::query()->create(['product_id' => $product->id, 'name' => 'In-Process Check']);
            $characteristic = QcCharacteristic::query()->create(['plan_id' => $plan->id, 'characteristic_name' => 'Temp', 'spec_type' => 'numeric']);

            $ids = ['batchPhase' => $batchPhase->id, 'characteristic' => $characteristic->id];
        });

        $this->post('/mes/qc-samples', [
            'batch_phase_id' => $ids['batchPhase'], 'output_id' => 999999,
            'results' => [['characteristic_id' => $ids['characteristic'], 'result' => 'fail']],
        ])->assertSessionHasNoErrors();

        $tenant->run(function () {
            $this->assertSame(0, QcHold::query()->count());
        });
    }

    public function test_qc_sample_validation_errors(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $product = $this->makeProduct('QC-5');
            $recipe = $this->makeRecipe($product->id);
            $order = ProdOrder::query()->create([
                'order_number' => 'WO-QC-5', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 1, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            $otherOrder = ProdOrder::query()->create([
                'order_number' => 'WO-QC-5B', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 1, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            $output = ProductionOutput::query()->create([
                'order_id' => $otherOrder->id, 'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $product->id, 'qty' => 1, 'created_at' => now(),
            ]);

            $ids = ['order' => $order->id, 'foreignOutput' => $output->id];
        });

        // neither order_id nor batch_phase_id.
        $this->post('/mes/qc-samples', [
            'results' => [['characteristic_id' => 1, 'result' => 'pass']],
        ])->assertSessionHasErrors('order_id');

        // invalid characteristic_id.
        $this->post('/mes/qc-samples', [
            'order_id' => $ids['order'],
            'results' => [['characteristic_id' => 999999, 'result' => 'pass']],
        ])->assertSessionHasErrors('results.0.characteristic_id');

        // output_id belongs to a different order.
        $this->post('/mes/qc-samples', [
            'order_id' => $ids['order'], 'output_id' => $ids['foreignOutput'],
            'results' => [['characteristic_id' => 1, 'result' => 'pass']],
        ])->assertSessionHasErrors('output_id');
    }

    public function test_releasing_a_qc_hold(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $holdId = null;
        $tenant->run(function () use (&$holdId) {
            $hold = QcHold::query()->create([
                'subject_type' => 'mes.mes_production_outputs', 'subject_id' => 1,
                'reason' => 'test hold', 'status' => QcHold::STATUS_OPEN, 'created_at' => now(),
            ]);
            $holdId = $hold->id;
        });

        $this->post("/mes/qc-holds/{$holdId}/release", ['note' => 'reviewed, ok'])
            ->assertSessionHasNoErrors();

        $tenant->run(function () use ($holdId) {
            $hold = QcHold::query()->find($holdId);
            $this->assertSame(QcHold::STATUS_RELEASED, $hold->status);
            $this->assertNotNull($hold->released_at);
        });

        $this->post("/mes/qc-holds/{$holdId}/release", ['note' => 'again'])
            ->assertSessionHasErrors('status');
    }

    public function test_prod_order_show_renders_qc_plan_samples_and_holds(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $product = $this->makeProduct('QC-6', ['tracking_mode' => Product::TRACKING_BATCH]);
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $recipe = $this->makeRecipe($product->id);

            $order = ProdOrder::query()->create([
                'order_number' => 'WO-QC-6', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            $this->receiveStock($warehouse, $product->id, 1, $product->base_uom_id, $location->id, 'QC-LOT-6');
            $lot = StockBatch::query()->where('product_id', $product->id)->first();

            ProductionOutput::query()->create([
                'order_id' => $order->id, 'output_type' => ProductionOutput::TYPE_FINISHED,
                'product_id' => $product->id, 'qty' => 1, 'lot_id' => $lot->id, 'created_at' => now(),
            ]);

            $plan = QcInspectionPlan::query()->create(['product_id' => $product->id, 'name' => 'FG Check']);
            $characteristic = QcCharacteristic::query()->create(['plan_id' => $plan->id, 'characteristic_name' => 'Weight', 'spec_type' => 'numeric']);

            $sample = QcSample::query()->create(['order_id' => $order->id, 'sample_number' => 'QC-SAMPLE-6', 'taken_by' => $this->adminUserId(), 'taken_at' => now()]);
            QcResult::query()->create(['sample_id' => $sample->id, 'characteristic_id' => $characteristic->id, 'actual_value' => 5, 'result' => 'pass']);

            QcHold::query()->create([
                'subject_type' => 'inventory.stock_batches', 'subject_id' => $lot->id,
                'reason' => 'fail on sample', 'status' => QcHold::STATUS_OPEN, 'created_at' => now(),
            ]);

            $ids = ['order' => $order->id];
        });

        $this->get("/mes/prod-orders/{$ids['order']}")->assertInertia(fn (Assert $page) => $page
            ->where('qcPlan.name', 'FG Check')
            ->has('qcPlan.characteristics', 1)
            ->has('qcSamples', 1)
            ->where('qcSamples.0.results.0.result', 'pass')
            ->has('qcHolds', 1)
        );
    }

    /** Relations no controller's own eager-load happens to touch: QcSample.order()/batchPhase(), QcCharacteristic.plan(), QcResult.characteristic(), QcHold.releasedBy(). */
    public function test_qc_model_relations(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('QC-7');
            $recipe = $this->makeRecipe($product->id, ['batch_size' => 10]);
            $workCenter = $this->makeWorkCenter('WC-QC7');
            $phase = ProcessPhase::query()->create(['recipe_id' => $recipe->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id]);
            $order = ProdOrder::query()->create([
                'order_number' => 'WO-QC-7', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 10, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            $batch = MesBatch::query()->create(['order_id' => $order->id, 'batch_number' => 'B-QC-7', 'recipe_id' => $recipe->id, 'status' => MesBatch::STATUS_RUNNING, 'planned_qty' => 10]);
            $batchPhase = BatchPhase::query()->create(['batch_id' => $batch->id, 'process_phase_id' => $phase->id, 'seq' => 10, 'status' => BatchPhase::STATUS_RUNNING]);

            $plan = QcInspectionPlan::query()->create(['product_id' => $product->id, 'name' => 'Relations Check']);
            $characteristic = QcCharacteristic::query()->create(['plan_id' => $plan->id, 'characteristic_name' => 'Temp', 'spec_type' => 'numeric']);

            $orderSample = QcSample::query()->create(['order_id' => $order->id, 'sample_number' => 'QC-SAMPLE-7A', 'taken_by' => $this->adminUserId(), 'taken_at' => now()]);
            $this->assertSame($order->id, $orderSample->order->id);

            $batchSample = QcSample::query()->create(['batch_phase_id' => $batchPhase->id, 'sample_number' => 'QC-SAMPLE-7B', 'taken_by' => $this->adminUserId(), 'taken_at' => now()]);
            $this->assertSame($batchPhase->id, $batchSample->batchPhase->id);
            $this->assertSame($this->adminUserId(), $batchSample->takenBy->id);

            $result = QcResult::query()->create(['sample_id' => $batchSample->id, 'characteristic_id' => $characteristic->id, 'actual_value' => 1, 'result' => 'pass']);
            $this->assertSame($characteristic->id, $result->characteristic->id);
            $this->assertSame($batchSample->id, $result->sample->id);
            $this->assertSame($plan->id, $characteristic->plan->id);

            $hold = QcHold::query()->create(['subject_type' => 'mes.mes_production_outputs', 'subject_id' => 1, 'status' => QcHold::STATUS_OPEN, 'created_at' => now()]);
            $hold->update(['status' => QcHold::STATUS_RELEASED, 'released_by' => $this->adminUserId(), 'released_at' => now()]);
            $this->assertSame($this->adminUserId(), $hold->releasedBy->id);
        });
    }
}
