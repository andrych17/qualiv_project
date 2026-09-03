<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\MES\Models\AndonAlert;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\MesBatch;
use App\Modules\MES\Models\ProcessPhase;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Models\QcHold;
use App\Modules\MES\Models\Routing;
use App\Modules\MES\Models\RoutingOp;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** MES_SPECS.md §3T — Dashboards, three focused read models over §3C/§3J/§3L/§3M/§3O. */
class MesDashboardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_plant_dashboard_aggregates_production_to_plan_downtime_and_reject_rate(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $day = null;
        $tenant->run(function () use (&$day) {
            $day = now()->startOfDay();
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'DASH-FG-01', 'name' => 'Dashboard Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);

            // Due today: one completed, one still in progress => 50% to plan.
            ProdOrder::query()->create([
                'order_number' => 'MO-DASH-DONE', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'qty' => 1, 'status' => ProdOrder::STATUS_COMPLETED, 'planned_end' => $day->copy()->addHours(10),
            ]);
            ProdOrder::query()->create([
                'order_number' => 'MO-DASH-WIP', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'qty' => 1, 'status' => ProdOrder::STATUS_IN_PROGRESS, 'planned_end' => $day->copy()->addHours(14),
            ]);

            $workCenter = WorkCenter::query()->create(['code' => 'WC-DASH', 'name' => 'Dash Line', 'type' => 'discrete']);
            $machine = Machine::query()->create(['work_center_id' => $workCenter->id, 'code' => 'M-DASH', 'name' => 'Dash Press', 'status' => 'idle']);

            // 30 minutes of downtime today.
            DowntimeEvent::query()->create([
                'machine_id' => $machine->id, 'category' => DowntimeEvent::CATEGORY_UNPLANNED, 'reason_code' => DowntimeEvent::REASON_MECHANICAL,
                'started_at' => $day->copy()->addHours(7), 'ended_at' => $day->copy()->addHours(7)->addMinutes(30),
            ]);

            // 6 good / 4 waste, plant-wide => reject rate 40%.
            ProductionOutput::query()->create(['order_id' => 1, 'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $product->id, 'qty' => 6, 'uom_code' => 'PCS', 'created_at' => $day->copy()->addHours(9)]);
            ProductionOutput::query()->create(['order_id' => 1, 'output_type' => ProductionOutput::TYPE_WASTE, 'product_id' => $product->id, 'qty' => 4, 'uom_code' => 'PCS', 'reason_code' => 'test', 'disposition' => 'scrap', 'created_at' => $day->copy()->addHours(9)]);
        });

        $this->get("/mes/dashboards/plant?date={$day->toDateString()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Dashboards/Plant')
                ->where('production_to_plan_pct', 50)
                ->where('downtime_minutes', 30)
                ->where('reject_rate_pct', 40)
                ->where('active_orders', 1)
            );
    }

    public function test_line_dashboard_returns_one_row_per_discrete_work_center(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $day = null;
        $tenant->run(function () use (&$day) {
            $day = now()->startOfDay();
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'DASH-LINE-01', 'name' => 'Dashboard Line Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);

            $workCenter = WorkCenter::query()->create(['code' => 'WC-LINE', 'name' => 'Line A', 'area_line' => 'Area 1', 'type' => 'discrete']);
            Machine::query()->create(['work_center_id' => $workCenter->id, 'code' => 'M-LINE', 'name' => 'Line Press', 'status' => Machine::STATUS_RUNNING]);

            $routing = Routing::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
            $op = RoutingOp::query()->create(['routing_id' => $routing->id, 'seq' => 1, 'op_code' => 'OP1', 'op_name' => 'Assemble', 'work_center_id' => $workCenter->id]);

            $order = ProdOrder::query()->create([
                'order_number' => 'MO-DASH-LINE', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => $routing->id, 'qty' => 10, 'status' => ProdOrder::STATUS_IN_PROGRESS, 'planned_end' => $day->copy()->addHours(10),
            ]);

            ProductionOutput::query()->create(['order_id' => $order->id, 'operation_ref' => $op->id, 'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $product->id, 'qty' => 7, 'uom_code' => 'PCS', 'created_at' => $day->copy()->addHours(9)]);
            ProductionOutput::query()->create(['order_id' => $order->id, 'operation_ref' => $op->id, 'output_type' => ProductionOutput::TYPE_WASTE, 'product_id' => $product->id, 'qty' => 3, 'uom_code' => 'PCS', 'reason_code' => 'test', 'disposition' => 'scrap', 'created_at' => $day->copy()->addHours(9)]);
        });

        $this->get("/mes/dashboards/line?date={$day->toDateString()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Dashboards/Line')
                ->where('lines', function ($lines) {
                    $row = collect($lines)->firstWhere('code', 'WC-LINE');

                    // Whole-number floats round-trip through JSON as bare integers (no
                    // JSON_PRESERVE_ZERO_FRACTION), so compare loosely here.
                    return $row !== null
                        && $row['running_state'] === 'running'
                        && $row['target_qty'] == 10
                        && $row['actual_qty'] == 7
                        && $row['reject_qty'] == 3;
                })
            );
    }

    public function test_process_area_dashboard_aggregates_active_batches_yield_alarms_and_holds(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $day = null;
        $tenant->run(function () use (&$day) {
            $day = now()->startOfDay();
            $uom = Uom::query()->create(['code' => 'KG', 'name' => 'Kilograms']);
            $product = Product::query()->create([
                'sku' => 'DASH-PROC-01', 'name' => 'Dashboard Process Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $recipe = Recipe::query()->create(['product_id' => $product->id, 'version' => 1, 'batch_size' => 100, 'is_active' => true]);
            $workCenter = WorkCenter::query()->create(['code' => 'WC-PROC', 'name' => 'Process Area', 'type' => 'process']);
            $phase = ProcessPhase::query()->create(['recipe_id' => $recipe->id, 'seq' => 1, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id]);

            $order = ProdOrder::query()->create([
                'order_number' => 'MO-DASH-PROC', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 100, 'status' => ProdOrder::STATUS_IN_PROGRESS,
            ]);

            $runningBatch = MesBatch::query()->create(['order_id' => $order->id, 'batch_number' => 'B-DASH-RUN', 'recipe_id' => $recipe->id, 'status' => MesBatch::STATUS_RUNNING, 'planned_qty' => 100]);
            $runningPhase = BatchPhase::query()->create(['batch_id' => $runningBatch->id, 'process_phase_id' => $phase->id, 'seq' => 1, 'status' => BatchPhase::STATUS_RUNNING]);

            $completedBatch = MesBatch::query()->create(['order_id' => $order->id, 'batch_number' => 'B-DASH-DONE', 'recipe_id' => $recipe->id, 'status' => MesBatch::STATUS_COMPLETED, 'planned_qty' => 100, 'actual_yield_pct' => 80]);

            AndonAlert::query()->create([
                'alert_type' => AndonAlert::TYPE_OUT_OF_SPEC_PARAMETER, 'subject_type' => 'mes.mes_batch_phases', 'subject_id' => $runningPhase->id,
                'severity' => 'critical', 'message' => 'test alarm', 'fired_at' => now(),
            ]);

            QcHold::query()->create(['subject_type' => 'inventory.stock_batches', 'subject_id' => 1, 'reason' => 'test', 'status' => QcHold::STATUS_OPEN, 'created_at' => now()]);

            $completedBatch->touch();
        });

        $this->get("/mes/dashboards/process-area?date={$day->toDateString()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Dashboards/ProcessArea')
                ->where('active_batches', 1)
                ->where('average_yield_pct', 80)
                ->where('parameter_alarm_count', 1)
                ->where('qc_hold_count', 1)
            );
    }
}
