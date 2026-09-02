<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\MES\Models\BatchParameterReading;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\MesBatch;
use App\Modules\MES\Models\ProcessParameter;
use App\Modules\MES\Models\ProcessPhase;
use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Models\QcCharacteristic;
use App\Modules\MES\Models\QcHold;
use App\Modules\MES\Models\QcInspectionPlan;
use App\Modules\MES\Models\QcResult;
use App\Modules\MES\Models\QcSample;
use App\Modules\MES\Models\Routing;
use App\Modules\MES\Models\RoutingOp;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * MES_SPECS.md §3O — OEE & Process KPIs, a pure read model. Numbers below are chosen to divide
 * evenly so rounding never masks a formula error (see OeeService's own docblock for why the
 * assembly branch is scoped to Work Center × Day rather than Machine).
 */
class MesOeeKpiTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_assembly_oee_combines_availability_performance_and_output_based_quality(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $workCenterId = null;
        $day = null;
        $tenant->run(function () use (&$workCenterId, &$day) {
            $day = now()->startOfDay();
            $userId = User::query()->first()->id;

            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'OEE-FG-01', 'name' => 'OEE Test Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);

            $workCenter = WorkCenter::query()->create(['code' => 'WC-OEE', 'name' => 'OEE Line', 'type' => 'discrete']);
            $workCenterId = $workCenter->id;
            $machine = Machine::query()->create(['work_center_id' => $workCenter->id, 'code' => 'M-OEE', 'name' => 'OEE Press', 'status' => 'idle']);

            $routing = Routing::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
            // 30 standard minutes to produce 10 units => 3 standard minutes/unit.
            $op = RoutingOp::query()->create([
                'routing_id' => $routing->id, 'seq' => 1, 'op_code' => 'OP1', 'op_name' => 'Assemble',
                'work_center_id' => $workCenter->id, 'setup_time_minutes' => 0, 'run_time_minutes' => 30,
                'queue_time_minutes' => 0, 'standard_output_qty' => 10,
            ]);

            $order = ProdOrder::query()->create([
                'order_number' => 'MO-OEE-1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'qty' => 10, 'uom_code' => 'PCS',
                'status' => ProdOrder::STATUS_IN_PROGRESS,
            ]);

            // Actual elapsed: 60 minutes (vs. 30 standard) => Performance = 50%.
            ProdEvent::query()->create([
                'order_id' => $order->id, 'operation_ref' => $op->id, 'event_type' => ProdEvent::TYPE_OPERATION_STARTED,
                'payload' => ['op_code' => 'OP1'], 'occurred_at' => $day->copy()->addHours(8), 'user_id' => $userId, 'machine_id' => $machine->id,
            ]);
            ProdEvent::query()->create([
                'order_id' => $order->id, 'operation_ref' => $op->id, 'event_type' => ProdEvent::TYPE_OPERATION_COMPLETED,
                'payload' => ['op_code' => 'OP1', 'qty_completed' => 10, 'qty_rejected' => 0], 'occurred_at' => $day->copy()->addHours(9), 'user_id' => $userId,
            ]);

            // 8 good / 2 waste => output-based Quality = 80%.
            ProductionOutput::query()->create([
                'order_id' => $order->id, 'operation_ref' => $op->id, 'output_type' => ProductionOutput::TYPE_FINISHED,
                'product_id' => $product->id, 'qty' => 8, 'uom_code' => 'PCS', 'created_at' => $day->copy()->addHours(9),
            ]);
            ProductionOutput::query()->create([
                'order_id' => $order->id, 'operation_ref' => $op->id, 'output_type' => ProductionOutput::TYPE_WASTE,
                'product_id' => $product->id, 'qty' => 2, 'uom_code' => 'PCS', 'reason_code' => 'test', 'disposition' => 'scrap',
                'created_at' => $day->copy()->addHours(9),
            ]);

            // 15 minutes unplanned downtime => Availability = 60 / (60 + 15) = 80%.
            DowntimeEvent::query()->create([
                'machine_id' => $machine->id, 'category' => DowntimeEvent::CATEGORY_UNPLANNED, 'reason_code' => DowntimeEvent::REASON_MECHANICAL,
                'started_at' => $day->copy()->addHours(7), 'ended_at' => $day->copy()->addHours(7)->addMinutes(15),
            ]);

            // §3L result, order-scoped, surfaced separately from the per-work-center Quality figure.
            $sample = QcSample::query()->create(['order_id' => $order->id, 'sample_number' => 'QC-OEE-1', 'taken_by' => $userId, 'taken_at' => $day->copy()->addHours(9)]);
            $characteristic = QcCharacteristic::query()->create(['plan_id' => QcInspectionPlan::query()->create(['product_id' => $product->id, 'name' => 'OEE QC Plan'])->id, 'characteristic_name' => 'Weight', 'spec_type' => 'numeric']);
            QcResult::query()->create(['sample_id' => $sample->id, 'characteristic_id' => $characteristic->id, 'result' => QcResult::RESULT_PASS]);
        });

        $this->get("/mes/oee?work_center_id={$workCenterId}&date={$day->toDateString()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Oee/Index')
                // Whole-number floats round-trip through JSON as bare integers (no
                // JSON_PRESERVE_ZERO_FRACTION), so assert against int literals here.
                ->where('assembly.availability_pct', 80)
                ->where('assembly.performance_pct', 50)
                ->where('assembly.quality_pct', 80)
                ->where('assembly.oee_pct', 32)
                ->where('assembly.operating_minutes', 60)
                ->where('assembly.downtime_minutes', 15)
                ->where('qc_pass_rate_pct', 100)
            );
    }

    public function test_process_kpis_yield_parameter_in_spec_and_open_qc_hold_count(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $day = null;
        $tenant->run(function () use (&$day) {
            $day = now()->startOfDay();

            $uom = Uom::query()->create(['code' => 'KG', 'name' => 'Kilograms']);
            $product = Product::query()->create([
                'sku' => 'OEE-PROC-01', 'name' => 'OEE Process Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $recipe = Recipe::query()->create(['product_id' => $product->id, 'version' => 1, 'batch_size' => 100, 'is_active' => true]);

            $order = ProdOrder::query()->create([
                'order_number' => 'MO-OEE-PROC-1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 100, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_IN_PROGRESS,
            ]);

            // 6 good / 4 waste => process Yield = 60%.
            ProductionOutput::query()->create([
                'order_id' => $order->id, 'output_type' => ProductionOutput::TYPE_FINISHED,
                'product_id' => $product->id, 'qty' => 6, 'uom_code' => 'KG', 'created_at' => $day->copy()->addHours(9),
            ]);
            ProductionOutput::query()->create([
                'order_id' => $order->id, 'output_type' => ProductionOutput::TYPE_WASTE,
                'product_id' => $product->id, 'qty' => 4, 'uom_code' => 'KG', 'reason_code' => 'test', 'disposition' => 'scrap',
                'created_at' => $day->copy()->addHours(9),
            ]);

            $phase = ProcessPhase::query()->create(['recipe_id' => $recipe->id, 'seq' => 1, 'phase_name' => 'Mix']);
            $parameter = ProcessParameter::query()->create(['process_phase_id' => $phase->id, 'parameter_code' => 'TEMP', 'min_value' => 10, 'max_value' => 20]);

            $batch = MesBatch::query()->create(['order_id' => $order->id, 'batch_number' => 'BATCH-OEE-1', 'recipe_id' => $recipe->id, 'status' => MesBatch::STATUS_RUNNING, 'planned_qty' => 100]);
            $batchPhase = BatchPhase::query()->create(['batch_id' => $batch->id, 'process_phase_id' => $phase->id, 'seq' => 1, 'status' => BatchPhase::STATUS_RUNNING]);

            // One in-spec (15, within [10,20]), one out-of-spec (25) => Parameter In-Spec = 50%.
            BatchParameterReading::query()->create(['batch_phase_id' => $batchPhase->id, 'process_parameter_id' => $parameter->id, 'value' => 15, 'recorded_at' => $day->copy()->addHours(9)]);
            BatchParameterReading::query()->create(['batch_phase_id' => $batchPhase->id, 'process_parameter_id' => $parameter->id, 'value' => 25, 'recorded_at' => $day->copy()->addHours(10)]);

            QcHold::query()->create(['subject_type' => 'inventory.stock_batches', 'subject_id' => 1, 'reason' => 'test', 'status' => QcHold::STATUS_OPEN, 'created_at' => now()]);
        });

        $this->get("/mes/oee?date={$day->toDateString()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Oee/Index')
                ->where('process.yield_pct', 60)
                ->where('process.parameter_in_spec_pct', 50)
                ->where('process.qc_hold_count', 1)
            );
    }
}
