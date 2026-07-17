<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUserLookup;
use App\Models\User;
use Database\Seeders\SysConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class TenantSwitchTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_switch_between_tenants(): void
    {
        $a = $this->provisionTenant('001');
        // Give tenant a display name
        $a->update(['name' => 'Firm A']);

        $this->dropTenantDatabaseIfExists('002');
        Tenant::query()->whereKey('002')->delete();
        TenantUserLookup::query()->where('tenant_id', '002')->delete();

        $b = Tenant::create(['id' => '002', 'name' => 'Firm B']);
        $b->run(function () {
            User::factory()->create([
                'name' => 'Admin User',
                'email' => 'admin@nusaevo.com',
                'password' => 'password',
                'email_verified_at' => now(),
            ]);
            $this->seed(SysConfigSeeder::class);
        });
        TenantUserLookup::query()->updateOrCreate(
            ['email' => 'admin@nusaevo.com', 'tenant_id' => '002'],
            [],
        );

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => '001',
        ])->assertRedirect('/dashboard');

        $this->assertSame('001', session('tenant_id'));

        $this->post('/tenant/switch', ['tenant_id' => '002'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame('002', session('tenant_id'));
        $this->assertAuthenticated();
    }
}
