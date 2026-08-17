<?php

namespace Tests\Feature\Central;

use App\Modules\Central\Models\CentralAdminUser;
use App\Modules\Central\Models\CentralPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralPlanCrudTest extends TestCase
{
    use RefreshDatabase;

    private CentralAdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // updateOrCreate: tests using SetsUpTenant disable transaction rollback (DROP
        // DATABASE can't run inside one), so a 'simon@nusaevo.com' row can already be
        // committed from an earlier test class in the same run.
        $this->admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );
    }

    public function test_admin_can_create_and_list_a_plan(): void
    {
        // Unique code (not reused by other test classes) — SetsUpTenant-based tests
        // elsewhere commit rows without rollback, so codes must not collide across files.
        $this->actingAs($this->admin, 'central_admin')
            ->post('/central/plans', [
                'code' => 'plan_crud_test_create',
                'name' => 'Plan CRUD Test Create',
                'price_monthly' => 1500000,
            ])
            ->assertRedirect(route('central.plans.index'));

        $this->assertDatabaseHas('central_plans', ['code' => 'plan_crud_test_create', 'name' => 'Plan CRUD Test Create']);

        $this->actingAs($this->admin, 'central_admin')
            ->get('/central/plans')
            ->assertInertia(fn ($page) => $page->component('Central/Plans/Index'));
    }

    public function test_deactivating_a_plan_flips_is_active_without_deleting_the_row(): void
    {
        $plan = CentralPlan::query()->create([
            'code' => 'plan_crud_test_deactivate',
            'name' => 'Plan CRUD Test Deactivate',
            'price_monthly' => 500000,
        ]);

        $this->actingAs($this->admin, 'central_admin')
            ->delete("/central/plans/{$plan->id}")
            ->assertRedirect(route('central.plans.index'));

        $this->assertDatabaseHas('central_plans', ['id' => $plan->id, 'is_active' => false]);
    }

    public function test_guest_cannot_reach_central_screens(): void
    {
        $this->get('/central/plans')->assertRedirect(route('central.login'));
    }
}
