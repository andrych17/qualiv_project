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

        $this->dropTenantDatabaseIfExists('001');
        Tenant::query()->whereKey('001')->delete();
    }

    protected function tearDown(): void
    {
        $this->dropTenantDatabaseIfExists('001');
        Tenant::query()->whereKey('001')->delete();

        parent::tearDown();
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

        $tenant = Tenant::query()->find('001');
        $this->assertNotNull($tenant);
        $this->assertSame('Demo Co', $tenant->name);
        $this->assertSame('starter', $tenant->plan);
        $this->assertSame('tenant_001', $tenant->tenant_db_name);
        $this->assertSame('provisioned', $tenant->provisioning_status);
        $this->assertNotNull($tenant->provisioned_at);

        $this->assertDatabaseHas('central_audit_logs', [
            'action' => 'tenant_registered',
            'entity_id' => '001',
        ]);

        // Proves the DB actually exists and is reachable, not just the central row.
        $databaseName = $tenant->run(fn () => DB::connection()->getDatabaseName());
        $this->assertSame('tenant_001', $databaseName);
    }
}
