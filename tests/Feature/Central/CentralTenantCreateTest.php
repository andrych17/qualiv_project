<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralAdminUser;
use App\Modules\Central\Models\CentralPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CentralTenantCreateTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanUpTestTenants();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTestTenants();

        parent::tearDown();
    }

    private function cleanUpTestTenants(): void
    {
        $tenantIds = Tenant::query()
            ->whereIn('id', ['001', '002', '003', '004', '005'])
            ->orWhere('name', 'Demo Co')
            ->pluck('id')
            ->merge(['001', '002', '003', '004', '005'])
            ->unique()
            ->all();

        foreach ($tenantIds as $id) {
            $this->dropTenantDatabaseIfExists((string) $id);
            Tenant::query()->whereKey($id)->delete();
        }
    }

    /** Registering a tenant via the admin screen really provisions a new Postgres DB (existing stancl pipeline). */
    public function test_registering_a_tenant_provisions_a_real_database(): void
    {
        $admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );

        CentralPlan::query()->updateOrCreate(
            ['code' => 'starter'],
            ['name' => 'Starter', 'price_monthly' => 500000],
        );

        $this->actingAs($admin, 'central_admin')
            ->post('/central/tenants', [
                'name' => 'Demo Co',
                'plan_code' => 'starter',
                'contact_email' => 'billing@demo.co',
            ])
            ->assertRedirect(route('central.tenants.index'));

        $tenant = Tenant::query()->where('name', 'Demo Co')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('Demo Co', $tenant->name);
        $this->assertSame('starter', $tenant->plan);
        $this->assertSame('tenant_'.$tenant->id, $tenant->tenant_db_name);
        $this->assertSame('provisioned', $tenant->provisioning_status);
        $this->assertNotNull($tenant->provisioned_at);

        $this->assertDatabaseHas('central_audit_logs', [
            'action' => 'tenant_registered',
            'entity_id' => (string) $tenant->id,
        ]);

        // Proves the DB actually exists and is reachable, not just the central row.
        $databaseName = $tenant->run(fn () => DB::connection()->getDatabaseName());
        $this->assertSame('tenant_'.$tenant->id, $databaseName);
    }

    /** A plan change is a billing-relevant event — it must be audit-logged (CENTRAL_SPECS.md §3C). */
    public function test_changing_a_tenants_plan_is_audit_logged(): void
    {
        $admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );

        CentralPlan::query()->updateOrCreate(['code' => 'starter'], ['name' => 'Starter', 'price_monthly' => 500000]);
        CentralPlan::query()->updateOrCreate(['code' => 'legal-pro'], ['name' => 'Legal Pro', 'price_monthly' => 2000000]);

        $this->dropTenantDatabaseIfExists('001');
        Tenant::query()->whereKey('001')->delete();
        $tenant = Tenant::create(['id' => '001', 'name' => 'Demo Co', 'plan' => 'starter']);

        $this->actingAs($admin, 'central_admin')
            ->put('/central/tenants/001', [
                'name' => 'Demo Co',
                'plan_code' => 'legal-pro',
            ])
            ->assertRedirect(route('central.tenants.index'));

        $this->assertDatabaseHas('tenants', ['id' => '001', 'plan' => 'legal-pro']);
        $this->assertDatabaseHas('central_audit_logs', [
            'action' => 'plan_changed',
            'entity_type' => 'tenant',
            'entity_id' => '001',
        ]);

        // Same plan re-submitted is not a change — no extra log row.
        $this->actingAs($admin, 'central_admin')
            ->put('/central/tenants/001', [
                'name' => 'Demo Co',
                'plan_code' => 'legal-pro',
            ])
            ->assertRedirect(route('central.tenants.index'));

        $this->assertSame(1, DB::table('central_audit_logs')
            ->where('entity_id', '001')->where('action', 'plan_changed')->count());
    }
}
