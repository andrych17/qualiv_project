<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralAdminUser;
use App\Modules\Central\Models\CentralTenantAddon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CentralTenantAddonTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private CentralAdminUser $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTenantDatabaseIfExists('addon1');
        Tenant::query()->whereKey('addon1')->delete();

        $this->admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );

        $this->tenant = Tenant::create(['id' => 'addon1', 'name' => 'Addon Co', 'plan' => 'starter']);
    }

    protected function tearDown(): void
    {
        $this->dropTenantDatabaseIfExists('addon1');
        Tenant::query()->whereKey('addon1')->delete();

        parent::tearDown();
    }

    public function test_admin_can_add_an_addon_to_a_tenant(): void
    {
        $this->actingAs($this->admin, 'central_admin')
            ->post("/central/tenants/{$this->tenant->getKey()}/addons", ['module_code' => 'performance'])
            ->assertRedirect(route('central.tenants.edit', $this->tenant->getKey()));

        $this->assertDatabaseHas('central_tenant_addons', [
            'tenant_id' => 'addon1',
            'module_code' => 'PERFORMANCE',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('central_audit_logs', ['action' => 'addon_added', 'entity_id' => 'addon1']);
    }

    public function test_admin_can_remove_an_addon_without_deleting_the_row(): void
    {
        $addon = CentralTenantAddon::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'module_code' => 'PERFORMANCE',
            'added_at' => now(),
            'status' => 'active',
        ]);

        $this->actingAs($this->admin, 'central_admin')
            ->delete("/central/tenants/{$this->tenant->getKey()}/addons/{$addon->id}")
            ->assertRedirect(route('central.tenants.edit', $this->tenant->getKey()));

        $this->assertDatabaseHas('central_tenant_addons', ['id' => $addon->id, 'status' => 'removed']);
        $this->assertDatabaseHas('central_audit_logs', ['action' => 'addon_removed', 'entity_id' => 'addon1']);
    }
}
