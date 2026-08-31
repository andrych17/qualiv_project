<?php

namespace Tests\Feature;

use App\Modules\PP\Models\CapacityPlan;
use App\Modules\PP\Models\Resource;
use App\Modules\PP\Models\ResourceGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** PP_SPECS.md §3F — Capacity Planning (RCCP): CRUD, the group-XOR-resource target rule, and the load%/overload calculation. */
class PPCapacityPlanTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_capacity_plan_crud_target_rule_and_overload_flag(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $groupId = null;
        $resourceId = null;
        $tenant->run(function () use (&$groupId, &$resourceId) {
            $groupId = ResourceGroup::query()->create(['code' => 'MIXING', 'name' => 'Mixing Group', 'is_active' => true])->id;
            $resourceId = Resource::query()->create(['type' => 'tool', 'code' => 'MIXER-01', 'name' => 'Mixer 01', 'is_active' => true])->id;
        });

        $this->get('/pp/capacity-plans')->assertOk()->assertInertia(fn ($page) => $page->component('PP/CapacityPlans/Index'));
        $this->get('/pp/capacity-plans/create')->assertOk()->assertInertia(fn ($page) => $page->component('PP/CapacityPlans/Create'));

        // Neither group nor resource chosen — rejected.
        $this->post('/pp/capacity-plans', [
            'period_start' => '2026-09-07', 'period_end' => '2026-09-13',
            'required_hours' => 620, 'available_hours' => 500,
        ])->assertSessionHasErrors('resource_group_id');

        // Both chosen — also rejected.
        $this->post('/pp/capacity-plans', [
            'resource_group_id' => $groupId, 'resource_type' => 'pp_resource', 'resource_ref_id' => $resourceId,
            'period_start' => '2026-09-07', 'period_end' => '2026-09-13',
            'required_hours' => 620, 'available_hours' => 500,
        ])->assertSessionHasErrors('resource_group_id');

        // Group only — accepted; 620/500 = 124% load, overloaded.
        $this->post('/pp/capacity-plans', [
            'resource_group_id' => $groupId,
            'period_start' => '2026-09-07', 'period_end' => '2026-09-13',
            'required_hours' => 620, 'available_hours' => 500,
        ])->assertRedirect('/pp/capacity-plans');

        $planId = null;
        $tenant->run(function () use (&$planId, $groupId) {
            $plan = CapacityPlan::query()->where('resource_group_id', $groupId)->first();
            $this->assertNotNull($plan);
            $this->assertEquals(620, $plan->required_hours);
            $planId = $plan->id;
        });

        $this->get('/pp/capacity-plans')->assertOk()->assertInertia(fn ($page) => $page
            ->component('PP/CapacityPlans/Index')
            ->where('plans.data.0.load_pct', 124)
            ->where('plans.data.0.is_overloaded', true));

        // An invalid pp_resource reference is rejected.
        $this->put("/pp/capacity-plans/{$planId}", [
            'resource_type' => 'pp_resource', 'resource_ref_id' => 999999,
            'period_start' => '2026-09-07', 'period_end' => '2026-09-13',
            'required_hours' => 400, 'available_hours' => 500,
        ])->assertSessionHasErrors('resource_ref_id');

        // Switch target to a single (real) pp_resource — 400/500 = 80%, not overloaded.
        $this->put("/pp/capacity-plans/{$planId}", [
            'resource_type' => 'pp_resource', 'resource_ref_id' => $resourceId,
            'period_start' => '2026-09-07', 'period_end' => '2026-09-13',
            'required_hours' => 400, 'available_hours' => 500,
        ])->assertRedirect('/pp/capacity-plans');

        $tenant->run(function () use ($planId, $resourceId) {
            $plan = CapacityPlan::query()->find($planId);
            $this->assertNull($plan->resource_group_id);
            $this->assertSame($resourceId, $plan->resource_ref_id);
        });

        $this->get("/pp/capacity-plans/{$planId}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('PP/CapacityPlans/Edit'));

        $this->delete("/pp/capacity-plans/{$planId}")->assertRedirect('/pp/capacity-plans');
        $tenant->run(function () use ($planId) {
            $this->assertNull(CapacityPlan::query()->find($planId));
        });
    }
}
