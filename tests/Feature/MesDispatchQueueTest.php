<?php

namespace Tests\Feature;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\Shift;
use App\Modules\HCM\Models\ShiftAssignment;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\MesAuditLog;
use App\Modules\MES\Models\MesBatch;
use App\Modules\MES\Models\ProcessPhase;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\Routing;
use App\Modules\MES\Models\RoutingOp;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\BomLine;
use App\Modules\PP\Models\Recipe;
use App\Modules\PP\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * MES_SPECS.md §3Q — MES Scheduling / live dispatch queue: priority + due date ordering,
 * material-availability flagging over BOM lines, work-center scoping, and the one write lever
 * (`promote`) — not a planning engine, see DispatchQueueService's own docblock for the boundary
 * with PP's §3H.
 */
class MesDispatchQueueTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_queue_sorts_by_priority_then_due_date_and_scopes_by_work_center(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $workCenterId = null;
        $otherWorkCenterId = null;
        $tenant->run(function () use (&$workCenterId, &$otherWorkCenterId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $workCenter = WorkCenter::query()->create(['code' => 'WC-DQ', 'name' => 'Dispatch Line', 'type' => 'discrete']);
            $workCenterId = $workCenter->id;
            $otherWorkCenter = WorkCenter::query()->create(['code' => 'WC-DQ-2', 'name' => 'Other Line', 'type' => 'discrete']);
            $otherWorkCenterId = $otherWorkCenter->id;

            $makeOrder = function (string $number, string $priority, string $dueOffset, int $wcId) use ($uom) {
                $product = Product::query()->create([
                    'sku' => "DQ-{$number}", 'name' => "DQ Widget {$number}", 'base_uom_id' => $uom->id,
                    'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
                ]);
                $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
                $routing = Routing::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
                RoutingOp::query()->create([
                    'routing_id' => $routing->id, 'seq' => 1, 'op_code' => 'OP1', 'op_name' => 'Assemble',
                    'work_center_id' => $wcId, 'setup_time_minutes' => 15, 'run_time_minutes' => 30,
                ]);

                return ProdOrder::query()->create([
                    'order_number' => "MO-DQ-{$number}", 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                    'bom_id' => $bom->id, 'routing_id' => $routing->id, 'qty' => 1,
                    'planned_end' => now()->addDays((int) $dueOffset), 'priority' => $priority,
                    'status' => ProdOrder::STATUS_RELEASED,
                ]);
            };

            // Same priority, different due dates — C (due sooner) must beat A. B (high) beats both regardless of due date.
            $makeOrder('A', 'low', '10', $workCenterId);
            $makeOrder('B', 'high', '10', $workCenterId);
            $makeOrder('C', 'low', '1', $workCenterId);
            // Different work center — must be excluded when filtering by $workCenterId.
            $makeOrder('D', 'urgent', '1', $otherWorkCenterId);
        });

        $this->get("/mes/dispatch-queue?work_center_id={$workCenterId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/DispatchQueue/Index')
                ->where('queue.0.order_number', 'MO-DQ-B')
                ->where('queue.1.order_number', 'MO-DQ-C')
                ->where('queue.2.order_number', 'MO-DQ-A')
                ->has('queue', 3)
            );

        $this->get('/mes/dispatch-queue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/DispatchQueue/Index')->has('queue', 4));
    }

    public function test_material_status_flags_shortage_available_and_unknown(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $tenant->run(function () {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $workCenter = WorkCenter::query()->create(['code' => 'WC-MAT', 'name' => 'Material Line', 'type' => 'discrete']);
            $warehouse = Warehouse::query()->create(['name' => 'DQ Warehouse', 'is_active' => true]);
            $location = Location::query()->create(['warehouse_id' => $warehouse->id, 'code' => 'DQ-L1', 'type' => 'storage', 'is_active' => true]);

            $component = Product::query()->create([
                'sku' => 'DQ-COMP', 'name' => 'DQ Component', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            StockBalance::query()->create(['product_id' => $component->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 10]);

            $makeOrder = function (string $number, float $requiredQty, ?int $warehouseId) use ($uom, $workCenter, $component) {
                $product = Product::query()->create([
                    'sku' => "DQ-MAT-{$number}", 'name' => "DQ Mat Widget {$number}", 'base_uom_id' => $uom->id,
                    'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
                ]);
                $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
                BomLine::query()->create(['bom_id' => $bom->id, 'component_product_id' => $component->id, 'qty_per_parent_unit' => $requiredQty]);
                $routing = Routing::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
                RoutingOp::query()->create(['routing_id' => $routing->id, 'seq' => 1, 'op_code' => 'OP1', 'op_name' => 'Assemble', 'work_center_id' => $workCenter->id]);

                ProdOrder::query()->create([
                    'order_number' => "MO-DQ-MAT-{$number}", 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                    'bom_id' => $bom->id, 'routing_id' => $routing->id, 'qty' => 1, 'warehouse_id' => $warehouseId,
                    'status' => ProdOrder::STATUS_RELEASED,
                ]);
            };

            $makeOrder('AVAIL', 5, $warehouse->id);   // needs 5, has 10 => available
            $makeOrder('SHORT', 50, $warehouse->id);  // needs 50, has 10 => shortage
            $makeOrder('UNK', 5, null);               // no warehouse => unknown
        });

        $this->get('/mes/dispatch-queue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/DispatchQueue/Index')
                ->where('queue', function ($queue) {
                    $byNumber = collect($queue)->keyBy('order_number');

                    return $byNumber['MO-DQ-MAT-AVAIL']['material_status'] === 'available'
                        && $byNumber['MO-DQ-MAT-SHORT']['material_status'] === 'shortage'
                        && $byNumber['MO-DQ-MAT-UNK']['material_status'] === 'unknown';
                })
            );
    }

    public function test_promote_bumps_priority_to_urgent_and_is_audited(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'DQ-PROMOTE', 'name' => 'DQ Promote Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
            $orderId = ProdOrder::query()->create([
                'order_number' => 'MO-DQ-PROMOTE', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'qty' => 1, 'priority' => 'low', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/dispatch-queue/{$orderId}/promote")->assertRedirect();

        $tenant->run(function () use ($orderId) {
            $this->assertSame('urgent', ProdOrder::query()->find($orderId)->priority);
            $this->assertSame(1, MesAuditLog::query()->where('subject_id', $orderId)->where('action', 'dispatch_promoted')->count());
        });
    }

    public function test_shift_in_session_reflects_an_active_shift_assignment_today(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/mes/dispatch-queue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/DispatchQueue/Index')->where('shiftInSession', false));

        $tenant->run(function () {
            $employee = Employee::query()->create(['employee_no' => 'EMP-DQ-1', 'full_name' => 'DQ Operator', 'hire_date' => now()->toDateString()]);
            $shift = Shift::query()->create(['name' => 'All Day', 'start_time' => '00:00:00', 'end_time' => '23:59:59', 'is_active' => true]);
            ShiftAssignment::query()->create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'work_date' => now()->toDateString()]);
        });

        $this->get('/mes/dispatch-queue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/DispatchQueue/Index')->where('shiftInSession', true));
    }

    /** currentProcessStep() (a process-model order's queue row) is only ever exercised by an assembly order elsewhere in this file — both the "no active phase yet" and "has an active phase" branches need their own process order. */
    public function test_process_model_orders_show_their_current_batch_phase_in_the_queue(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $tenant->run(function () {
            $uom = Uom::query()->create(['code' => 'PCS-DQP', 'name' => 'Pieces DQP']);
            $workCenter = WorkCenter::query()->create(['code' => 'WC-DQP', 'name' => 'DQ Process Line', 'type' => 'process']);

            $productNoBatch = Product::query()->create([
                'sku' => 'DQ-PROC-NOBATCH', 'name' => 'DQ Process No Batch', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $recipeNoBatch = Recipe::query()->create(['product_id' => $productNoBatch->id, 'version' => 1, 'batch_size' => 10, 'is_active' => true]);
            ProdOrder::query()->create([
                'order_number' => 'MO-DQ-PROC-NOBATCH', 'product_id' => $productNoBatch->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeNoBatch->id, 'qty' => 10, 'uom_code' => 'PCS-DQP', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            $productWithBatch = Product::query()->create([
                'sku' => 'DQ-PROC-BATCH', 'name' => 'DQ Process With Batch', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $recipeWithBatch = Recipe::query()->create(['product_id' => $productWithBatch->id, 'version' => 1, 'batch_size' => 10, 'is_active' => true]);
            $phase = ProcessPhase::query()->create(['recipe_id' => $recipeWithBatch->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id]);
            $order = ProdOrder::query()->create([
                'order_number' => 'MO-DQ-PROC-BATCH', 'product_id' => $productWithBatch->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeWithBatch->id, 'qty' => 10, 'uom_code' => 'PCS-DQP', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            $batch = MesBatch::query()->create(['order_id' => $order->id, 'batch_number' => 'B-DQ-PROC', 'recipe_id' => $recipeWithBatch->id, 'status' => MesBatch::STATUS_RUNNING, 'planned_qty' => 10]);
            BatchPhase::query()->create(['batch_id' => $batch->id, 'process_phase_id' => $phase->id, 'seq' => 10, 'status' => BatchPhase::STATUS_RUNNING]);
        });

        $this->get('/mes/dispatch-queue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/DispatchQueue/Index')
                ->where('queue', function ($queue) {
                    $byNumber = collect($queue)->keyBy('order_number');

                    return $byNumber['MO-DQ-PROC-NOBATCH']['current_step_code'] === null
                        && str_starts_with($byNumber['MO-DQ-PROC-BATCH']['current_step_code'], 'PHASE-')
                        && $byNumber['MO-DQ-PROC-BATCH']['current_step_name'] === 'Mix';
                })
            );
    }

    /** promote()'s own status guard — an order not in released/in_progress/paused is rejected. */
    public function test_promote_rejects_a_draft_order(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $uom = Uom::query()->create(['code' => 'PCS-DQD', 'name' => 'Pieces DQD']);
            $product = Product::query()->create([
                'sku' => 'DQ-DRAFT', 'name' => 'DQ Draft Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
            $orderId = ProdOrder::query()->create([
                'order_number' => 'MO-DQ-DRAFT', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'qty' => 1, 'status' => ProdOrder::STATUS_DRAFT,
            ])->id;
        });

        $this->post("/mes/dispatch-queue/{$orderId}/promote")->assertSessionHasErrors('status');
    }

    /** currentAssemblyStep()'s "no current op" branch — a released order whose routing never resolved (no active Routing existed for the product at creation time; the CHECK constraint only requires bom_id, not routing_id, for an assembly order). */
    public function test_an_assembly_order_with_no_routing_shows_a_blank_current_step(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $tenant->run(function () {
            $uom = Uom::query()->create(['code' => 'PCS-DQR', 'name' => 'Pieces DQR']);
            $product = Product::query()->create([
                'sku' => 'DQ-NOROUTE', 'name' => 'DQ No Route Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
            ProdOrder::query()->create([
                'order_number' => 'MO-DQ-NOROUTE', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'routing_id' => null, 'qty' => 1, 'status' => ProdOrder::STATUS_RELEASED,
            ]);
        });

        $this->get('/mes/dispatch-queue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/DispatchQueue/Index')
                ->where('queue', function ($queue) {
                    $row = collect($queue)->firstWhere('order_number', 'MO-DQ-NOROUTE');

                    return $row !== null && $row['current_step_code'] === null && $row['work_center_id'] === null;
                })
            );
    }

    /** processRequirements() (a process order's recipe-ingredient material check) is only ever exercised by an assembly order's BOM elsewhere in this file. */
    public function test_material_status_for_a_process_order_reads_from_recipe_ingredients(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $tenant->run(function () {
            $uom = Uom::query()->create(['code' => 'KG-DQP', 'name' => 'Kilograms DQP']);
            $warehouse = Warehouse::query()->create(['name' => 'DQ Process Warehouse', 'is_active' => true]);
            $location = Location::query()->create(['warehouse_id' => $warehouse->id, 'code' => 'DQ-PROC-L1', 'type' => 'storage', 'is_active' => true]);

            $rawMaterial = Product::query()->create([
                'sku' => 'DQ-PROC-RAW', 'name' => 'DQ Process Raw', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            StockBalance::query()->create(['product_id' => $rawMaterial->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 10]);

            $product = Product::query()->create([
                'sku' => 'DQ-PROC-FG', 'name' => 'DQ Process FG', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $recipe = Recipe::query()->create(['product_id' => $product->id, 'version' => 1, 'batch_size' => 10, 'is_active' => true]);
            RecipeIngredient::query()->create(['recipe_id' => $recipe->id, 'raw_material_product_id' => $rawMaterial->id, 'qty_per_batch' => 50]);

            ProdOrder::query()->create([
                'order_number' => 'MO-DQ-PROC-SHORT', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'warehouse_id' => $warehouse->id, 'qty' => 10, 'uom_code' => 'KG-DQP', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
        });

        $this->get('/mes/dispatch-queue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/DispatchQueue/Index')
                ->where('queue', function ($queue) {
                    $row = collect($queue)->firstWhere('order_number', 'MO-DQ-PROC-SHORT');

                    // batch_size 10, order qty 10 => scale 1.0; needs 50/unit-batch, has 10 => shortage.
                    return $row !== null && $row['material_status'] === 'shortage';
                })
            );
    }

    /** materialStatus()'s "requirements resolved but empty" branch — an active BOM with zero lines reads as `unknown`, same as no warehouse at all. */
    public function test_material_status_is_unknown_when_the_active_bom_has_no_lines(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $tenant->run(function () {
            $uom = Uom::query()->create(['code' => 'PCS-DQE', 'name' => 'Pieces DQE']);
            $warehouse = Warehouse::query()->create(['name' => 'DQ Empty BOM Warehouse', 'is_active' => true]);
            $product = Product::query()->create([
                'sku' => 'DQ-EMPTYBOM', 'name' => 'DQ Empty BOM Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
            ProdOrder::query()->create([
                'order_number' => 'MO-DQ-EMPTYBOM', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'warehouse_id' => $warehouse->id, 'qty' => 1, 'status' => ProdOrder::STATUS_RELEASED,
            ]);
        });

        $this->get('/mes/dispatch-queue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/DispatchQueue/Index')
                ->where('queue', function ($queue) {
                    $row = collect($queue)->firstWhere('order_number', 'MO-DQ-EMPTYBOM');

                    return $row !== null && $row['material_status'] === 'unknown';
                })
            );
    }
}
