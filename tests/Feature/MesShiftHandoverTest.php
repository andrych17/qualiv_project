<?php

namespace Tests\Feature;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\Shift;
use App\Modules\HCM\Models\ShiftAssignment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ShiftHandoverNote;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\PP\Models\Bom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * MES_SPECS.md §3P — Shift Reference & Handover: no MES-owned shift model (reads HCM's own
 * shifts/shift_assignments), and the one MES-owned table captures an order/batch summary at the
 * moment of handover, not a live-recomputed one.
 */
class MesShiftHandoverTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_recording_a_handover_note_captures_a_point_in_time_summary(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $shiftAssignmentId = null;
        $tenant->run(function () use (&$shiftAssignmentId) {
            $employee = Employee::query()->create(['employee_no' => 'EMP-001', 'full_name' => 'Op One', 'hire_date' => now()->toDateString()]);
            $shift = Shift::query()->create(['name' => 'Day Shift', 'start_time' => '00:00:00', 'end_time' => '23:59:59', 'break_minutes' => 30, 'is_active' => true]);
            $shiftAssignmentId = ShiftAssignment::query()->create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'work_date' => now()->toDateString()])->id;

            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'SHIFT-FG-01', 'name' => 'Shift Test Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
            ProdOrder::query()->create([
                'order_number' => 'MO-SHIFT-1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'qty' => 5, 'status' => ProdOrder::STATUS_IN_PROGRESS,
            ]);

            $workCenter = WorkCenter::query()->create(['code' => 'WC-SHIFT', 'name' => 'Shift Line', 'type' => 'discrete']);
            $machine = Machine::query()->create(['work_center_id' => $workCenter->id, 'code' => 'M-SHIFT', 'name' => 'Shift Press', 'status' => 'down']);
            DowntimeEvent::query()->create([
                'machine_id' => $machine->id, 'category' => DowntimeEvent::CATEGORY_UNPLANNED, 'reason_code' => DowntimeEvent::REASON_MECHANICAL, 'started_at' => now(),
            ]);
        });

        $this->post('/mes/shift-handovers', [
            'shift_assignment_id' => $shiftAssignmentId,
            'notes' => 'Machine 2 making a grinding noise, keep an eye on it.',
        ])->assertRedirect('/mes/shift-handovers');

        $tenant->run(function () use ($shiftAssignmentId) {
            $note = ShiftHandoverNote::query()->where('shift_assignment_id', $shiftAssignmentId)->first();
            $this->assertNotNull($note);
            $this->assertSame('Machine 2 making a grinding noise, keep an eye on it.', $note->notes);
            $this->assertCount(1, $note->order_summary['active_orders']);
            $this->assertSame('MO-SHIFT-1', $note->order_summary['active_orders'][0]['order_number']);
            $this->assertSame(0, $note->order_summary['open_qc_hold_count']);
            $this->assertSame(1, $note->order_summary['open_downtime_count']);
            $this->assertNotNull($note->created_by);
        });

        $this->get('/mes/shift-handovers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/ShiftHandovers/Index')
                ->where('notes.total', 1)
                ->where('notes.data.0.employee_name', 'Op One')
            );
    }

    public function test_an_invalid_shift_assignment_is_rejected(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->post('/mes/shift-handovers', [
            'shift_assignment_id' => 999999,
            'notes' => 'x',
        ])->assertSessionHasErrors('shift_assignment_id');
    }
}
