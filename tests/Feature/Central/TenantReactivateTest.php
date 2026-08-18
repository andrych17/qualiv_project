<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralAdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class TenantReactivateTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanUpTenants();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTenants();

        parent::tearDown();
    }

    private function cleanUpTenants(): void
    {
        foreach (['807', '808'] as $id) {
            $this->dropTenantDatabaseIfExists($id);
            Tenant::query()->whereKey($id)->delete();
        }
    }

    public function test_admin_can_manually_reactivate_a_read_only_tenant_with_a_reason(): void
    {
        $admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );

        $tenant = Tenant::create(['id' => '807', 'name' => 'Reactivate Co', 'plan' => 'starter', 'access_status' => 'read_only']);

        $this->actingAs($admin, 'central_admin')
            ->post("/central/tenants/{$tenant->getKey()}/reactivate", ['reason' => 'Comped for Q3 pilot'])
            ->assertRedirect();

        $this->assertSame('active', $tenant->refresh()->access_status);

        $this->assertDatabaseHas('central_audit_logs', [
            'action' => 'access_status_changed',
            'entity_type' => 'tenant',
            'entity_id' => '807',
        ]);
    }

    public function test_reactivate_requires_a_reason(): void
    {
        $admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );

        $tenant = Tenant::create(['id' => '808', 'name' => 'No Reason Co', 'plan' => 'starter', 'access_status' => 'read_only']);

        $this->actingAs($admin, 'central_admin')
            ->post("/central/tenants/{$tenant->getKey()}/reactivate", [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('read_only', $tenant->refresh()->access_status);
    }
}
