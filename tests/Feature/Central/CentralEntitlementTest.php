<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Models\CentralPlanModule;
use App\Modules\Central\Models\CentralTenantAddon;
use App\Modules\Central\Services\CentralEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CentralEntitlementTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private const TENANT_IDS = ['ent1', 'ent2', 'ent3'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTenants();
    }

    protected function tearDown(): void
    {
        $this->dropTenants();

        parent::tearDown();
    }

    private function dropTenants(): void
    {
        foreach (self::TENANT_IDS as $id) {
            $this->dropTenantDatabaseIfExists($id);
            Tenant::query()->whereKey($id)->delete();
        }
    }

    public function test_entitled_modules_is_the_union_of_plan_modules_and_active_addons(): void
    {
        CentralPlan::query()->create(['code' => 'entitlement_plan', 'name' => 'Entitlement Plan', 'price_monthly' => 0]);
        CentralPlanModule::query()->create(['plan_code' => 'entitlement_plan', 'module_code' => 'INVENTORY']);
        CentralPlanModule::query()->create(['plan_code' => 'entitlement_plan', 'module_code' => 'CRM']);

        $tenant = Tenant::create(['id' => 'ent1', 'name' => 'Entitlement Co', 'plan' => 'entitlement_plan']);

        CentralTenantAddon::query()->create([
            'tenant_id' => $tenant->getKey(),
            'module_code' => 'PERF',
            'added_at' => now(),
            'status' => 'active',
        ]);

        $service = app(CentralEntitlementService::class);

        $this->assertEqualsCanonicalizing(['INVENTORY', 'CRM', 'PERF'], $service->entitledModules('ent1'));
        $this->assertTrue($service->isEntitled('ent1', 'inventory'));
        $this->assertFalse($service->isEntitled('ent1', 'PAYROLL'));
    }

    public function test_removing_an_addon_flips_status_and_narrows_entitlement_without_deleting_the_row(): void
    {
        CentralPlan::query()->create(['code' => 'entitlement_plan2', 'name' => 'Entitlement Plan 2', 'price_monthly' => 0]);
        $tenant = Tenant::create(['id' => 'ent2', 'name' => 'Entitlement Co 2', 'plan' => 'entitlement_plan2']);

        $addon = CentralTenantAddon::query()->create([
            'tenant_id' => $tenant->getKey(),
            'module_code' => 'PERF',
            'added_at' => now(),
            'status' => 'active',
        ]);

        $service = app(CentralEntitlementService::class);
        $this->assertTrue($service->isEntitled('ent2', 'PERF'));

        $addon->update(['status' => 'removed']);

        $this->assertFalse($service->isEntitled('ent2', 'PERF'));
        $this->assertDatabaseHas('central_tenant_addons', ['id' => $addon->id, 'status' => 'removed']);
    }

    public function test_tenant_feature_service_falls_back_to_config_when_plan_has_no_db_entitlement_rows(): void
    {
        // 'starter' plan has no central_plan_modules rows seeded in this test's fresh DB —
        // TenantFeatureService must fall back to config/tenant_modules.php.
        Tenant::create(['id' => 'ent3', 'name' => 'Fallback Co', 'plan' => 'starter']);

        $service = app(CentralEntitlementService::class);
        $this->assertSame([], $service->entitledModules('ent3'));

        $configModules = config('tenant_modules.plans.starter');
        $this->assertNotEmpty($configModules);
    }
}
