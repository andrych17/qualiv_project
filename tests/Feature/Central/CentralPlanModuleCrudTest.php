<?php

namespace Tests\Feature\Central;

use App\Modules\Central\Models\CentralAdminUser;
use App\Modules\Central\Models\CentralPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralPlanModuleCrudTest extends TestCase
{
    use RefreshDatabase;

    private CentralAdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );
    }

    public function test_creating_a_plan_persists_module_codes_and_logs_it(): void
    {
        $this->actingAs($this->admin, 'central_admin')
            ->post('/central/plans', [
                'code' => 'plan_module_crud_create',
                'name' => 'Plan Module Crud Create',
                'price_monthly' => 500000,
                'module_codes' => ['inventory', 'crm'],
            ])
            ->assertRedirect(route('central.plans.index'));

        $this->assertDatabaseHas('central_plan_modules', ['plan_code' => 'plan_module_crud_create', 'module_code' => 'INVENTORY']);
        $this->assertDatabaseHas('central_plan_modules', ['plan_code' => 'plan_module_crud_create', 'module_code' => 'CRM']);
        $this->assertDatabaseHas('central_audit_logs', ['action' => 'plan_changed', 'entity_id' => 'plan_module_crud_create']);
    }

    public function test_updating_a_plan_resyncs_module_codes(): void
    {
        $plan = CentralPlan::query()->create(['code' => 'plan_module_crud_update', 'name' => 'Before', 'price_monthly' => 100]);

        $this->actingAs($this->admin, 'central_admin')
            ->put("/central/plans/{$plan->id}", [
                'name' => 'After',
                'price_monthly' => 200,
                'module_codes' => ['sales'],
            ])
            ->assertRedirect(route('central.plans.index'));

        $this->assertDatabaseHas('central_plan_modules', ['plan_code' => 'plan_module_crud_update', 'module_code' => 'SALES']);
        $this->assertDatabaseMissing('central_plan_modules', ['plan_code' => 'plan_module_crud_update', 'module_code' => 'CRM']);
    }
}
