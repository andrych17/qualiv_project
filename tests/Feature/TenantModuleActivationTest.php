<?php

namespace Tests\Feature;

use App\Modules\SysConfig\Models\ConfigAuditLog;
use App\Modules\SysConfig\Models\TenantModule;
use App\Services\TenantFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class TenantModuleActivationTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_toggle_entitled_module_and_cannot_enable_unentitled(): void
    {
        $tenant = $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/config/modules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Config/Modules/Index'));

        $this->patch('/config/modules/LEGAL', ['is_active' => true])
            ->assertSessionHasErrors('is_active');

        $this->patch('/config/modules/INVENTORY', ['is_active' => false])
            ->assertRedirect();

        $tenant->run(function () {
            $row = TenantModule::query()->where('module_code', 'INVENTORY')->first();
            $this->assertNotNull($row);
            $this->assertFalse($row->is_active);
            $this->assertFalse(app(TenantFeatureService::class)->enabled('INVENTORY'));
            $this->assertTrue(
                ConfigAuditLog::query()
                    ->where('table_name', 'tenant_modules')
                    ->where('action', 'deactivated')
                    ->exists()
            );
        });

        $this->get('/inventory/items')->assertForbidden();

        $this->patch('/config/modules/INVENTORY', ['is_active' => true])
            ->assertRedirect();

        $tenant->run(function () {
            $this->assertTrue(app(TenantFeatureService::class)->enabled('INVENTORY'));
        });
    }
}
