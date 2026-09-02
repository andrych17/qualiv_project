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
use App\Modules\MES\Models\MesAuditLog;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\Routing;
use App\Modules\MES\Models\RoutingOp;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\BomLine;
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
}
