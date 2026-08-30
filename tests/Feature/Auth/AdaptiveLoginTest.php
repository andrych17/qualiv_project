<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\TenantUserLookup;
use App\Models\User;
use Database\Seeders\SysConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class AdaptiveLoginTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_email_lookup_returns_associated_tenants(): void
    {
        $tenantA = $this->provisionTenant('001');
        $tenantA->update(['name' => 'Firm Alpha']);

        $response = $this->postJson('/login/lookup', [
            'email' => 'admin@nusaevo.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'tenants' => [
                    [
                        'id' => '001',
                        'name' => 'Firm Alpha',
                    ],
                ],
            ]);
    }

    public function test_email_lookup_for_unregistered_email_returns_empty_tenants(): void
    {
        $this->provisionTenant('001');

        $response = $this->postJson('/login/lookup', [
            'email' => 'unknown@example.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'tenants' => [],
            ]);
    }

    public function test_email_lookup_for_multi_tenant_user_returns_all_tenants(): void
    {
        $tenantA = $this->provisionTenant('001');
        $tenantA->update(['name' => 'Firm Alpha']);

        $this->dropTenantDatabaseIfExists('002');
        Tenant::query()->whereKey('002')->delete();
        TenantUserLookup::query()->where('tenant_id', '002')->delete();

        $tenantB = Tenant::create(['id' => '002', 'name' => 'Firm Beta']);
        $tenantB->run(function () {
            User::factory()->create([
                'name' => 'Admin User',
                'email' => 'admin@nusaevo.com',
                'password' => 'password_beta',
                'email_verified_at' => now(),
            ]);
            $this->seed(SysConfigSeeder::class);
        });

        TenantUserLookup::query()->updateOrCreate(
            ['email' => 'admin@nusaevo.com', 'tenant_id' => '002'],
            [],
        );

        $response = $this->postJson('/login/lookup', [
            'email' => 'admin@nusaevo.com',
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'tenants')
            ->assertJsonFragment(['id' => '001', 'name' => 'Firm Alpha'])
            ->assertJsonFragment(['id' => '002', 'name' => 'Firm Beta']);
    }

    public function test_multi_tenant_user_can_login_to_selected_tenant_with_specific_password(): void
    {
        $tenantA = $this->provisionTenant('001', 'consultant@example.com', 'password_alpha');
        $tenantA->update(['name' => 'Firm Alpha']);

        $this->dropTenantDatabaseIfExists('002');
        Tenant::query()->whereKey('002')->delete();
        TenantUserLookup::query()->where('tenant_id', '002')->delete();

        $tenantB = Tenant::create(['id' => '002', 'name' => 'Firm Beta']);
        $tenantB->run(function () {
            User::factory()->create([
                'name' => 'Consultant User',
                'email' => 'consultant@example.com',
                'password' => 'password_beta',
                'email_verified_at' => now(),
                'is_active' => true,
            ]);
            $this->seed(SysConfigSeeder::class);
        });

        TenantUserLookup::query()->updateOrCreate(
            ['email' => 'consultant@example.com', 'tenant_id' => '002'],
            [],
        );

        // 1. Login to Tenant A with Password Alpha
        $responseA = $this->post('/login', [
            'email' => 'consultant@example.com',
            'password' => 'password_alpha',
            'tenant_id' => '001',
        ]);
        $responseA->assertRedirect(route('dashboard', absolute: false));
        $this->assertSame('001', tenant('id'));

        // Logout
        $this->post('/logout');

        // 2. Login to Tenant B with Password Beta
        $responseB = $this->post('/login', [
            'email' => 'consultant@example.com',
            'password' => 'password_beta',
            'tenant_id' => '002',
        ]);
        $responseB->assertRedirect(route('dashboard', absolute: false));
        $this->assertSame('002', tenant('id'));
    }

    public function test_multi_tenant_user_without_tenant_id_authenticates_to_matching_tenant(): void
    {
        $tenantA = $this->provisionTenant('001', 'multi@example.com', 'pass1');

        $this->dropTenantDatabaseIfExists('002');
        Tenant::query()->whereKey('002')->delete();
        TenantUserLookup::query()->where('tenant_id', '002')->delete();

        $tenantB = Tenant::create(['id' => '002', 'name' => 'Firm Beta']);
        $tenantB->run(function () {
            User::factory()->create([
                'name' => 'Multi User',
                'email' => 'multi@example.com',
                'password' => 'pass2',
                'email_verified_at' => now(),
                'is_active' => true,
            ]);
            $this->seed(SysConfigSeeder::class);
        });

        TenantUserLookup::query()->updateOrCreate(
            ['email' => 'multi@example.com', 'tenant_id' => '002'],
            [],
        );

        // 1. Password pass1 matches tenant 001
        $response = $this->post('/login', [
            'email' => 'multi@example.com',
            'password' => 'pass1',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertSame('001', tenant('id'));

        // Logout
        $this->post('/logout');

        // 2. Password pass2 matches tenant 002 without providing tenant_id
        $response2 = $this->post('/login', [
            'email' => 'multi@example.com',
            'password' => 'pass2',
        ]);

        $this->assertAuthenticated();
        $response2->assertRedirect(route('dashboard', absolute: false));
        $this->assertSame('002', tenant('id'));
    }
}
