<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\Resource;
use App\Modules\PP\Models\ResourceGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * PP_SPECS.md §3O — Production Planning Dashboard: pure read model over §3B (demand)/§3D (planned
 * orders)/§3F (capacity)/§3M (exceptions), no dashboard-only storage. Proves each figure traces to
 * real data rather than asserting the page merely renders.
 */
class PPDashboardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_dashboard_aggregates_demand_planned_capacity_and_exceptions(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $groupId = null;
        $fgId = null;
        $tankId = null;
        $tenant->run(function () use (&$groupId, &$fgId, &$tankId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $fg = Product::query()->create([
                'sku' => 'DASH-FG-01', 'name' => 'Dashboard Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $fgId = $fg->id;
            $bom = Bom::query()->create(['product_id' => $fg->id, 'version' => 1, 'is_active' => true]);

            // Demand for 100 this month, only 60 planned — a real 40-unit gap.
            PlannedOrder::query()->create([
                'plan_number' => 'PLN-DASH-0001', 'order_type' => PlannedOrder::TYPE_PRODUCTION,
                'product_id' => $fg->id, 'qty' => 60, 'need_by_date' => now()->addDays(3)->toDateString(),
                'bom_id' => $bom->id, 'status' => PlannedOrder::STATUS_PLANNED,
            ]);

            $groupId = ResourceGroup::query()->create(['code' => 'DASH-CUT', 'name' => 'Cutting', 'is_active' => true])->id;
            $tankId = Resource::query()->create([
                'type' => Resource::TYPE_TANK, 'code' => 'DASH-TANK-01', 'name' => 'Dashboard Tank',
                'uom_code' => 'L', 'is_active' => true,
            ])->id;
        });

        $this->post('/pp/demand', [
            'demand_date' => now()->toDateString(),
            'lines' => [['product_id' => $fgId, 'need_by_date' => now()->addDays(3)->toDateString(), 'qty' => 100]],
        ])->assertRedirect('/pp/demand');

        // Cutting group, current period — 89% load, not overloaded.
        $this->post('/pp/capacity-plans', [
            'resource_group_id' => $groupId,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'required_hours' => 890,
            'available_hours' => 1000,
        ])->assertRedirect('/pp/capacity-plans');

        // A single pp_resource (no group) — 120% load, overloaded, writes a §3F exception.
        // Proves capacityBars() picks up resource-keyed plans, not just group-keyed ones
        // (CapacityPlanService::attributes() makes the two mutually exclusive).
        $this->post('/pp/capacity-plans', [
            'resource_type' => 'pp_resource', 'resource_ref_id' => $tankId,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'required_hours' => 1200,
            'available_hours' => 1000,
        ])->assertRedirect('/pp/capacity-plans');

        $this->get('/pp/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('PP/Dashboard')
                ->where('demand_qty', 100)
                ->where('planned_qty', 60)
                ->where('gap_qty', 40)
                // Average of each dimension's worst-case load — (120 + 89) / 2.
                ->where('capacity_pct', 104.5)
                ->where('capacity_bars.0.label', 'PP RESOURCE #'.$tankId)
                ->where('capacity_bars.0.load_pct', 120)
                ->where('capacity_bars.0.overloaded', true)
                ->where('capacity_bars.1.label', 'Cutting')
                ->where('capacity_bars.1.load_pct', 89)
                ->where('capacity_bars.1.overloaded', false)
                ->where('material_pct', 100)
                ->where('on_time_pct', 100)
                ->where('exception_counts.capacity_overload', 1)
                ->where('orders_ready_count', 1)
            );
    }

    public function test_dashboard_handles_a_tenant_with_no_data_yet(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/pp/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('PP/Dashboard')
                ->where('demand_qty', 0)
                ->where('planned_qty', 0)
                ->where('gap_qty', 0)
                ->where('capacity_pct', null)
                ->where('material_pct', null)
                ->where('on_time_pct', null)
                ->where('capacity_bars', [])
                ->where('exception_counts', [])
                ->where('orders_ready_count', 0)
            );

        // /pp itself now lands on the dashboard (§3O ships the module's overview page).
        $this->get('/pp')->assertRedirect('/pp/dashboard');
    }
}
