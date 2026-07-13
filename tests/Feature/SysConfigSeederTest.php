<?php

namespace Tests\Feature;

use App\Modules\Config\Models\ConfigConst;
use App\Modules\Config\Models\ConfigMenu;
use App\Modules\Config\Services\ConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class SysConfigSeederTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_sysconfig_seed_gives_admin_menus_and_consts(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $this->assertGreaterThanOrEqual(2, ConfigMenu::query()->count());
            $this->assertNotNull(
                ConfigConst::query()->where('const_group', 'APP')->where('group_code', 'NAME')->first()
            );

            $adminId = (int) \App\Models\User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $menus = app(ConfigService::class)->menusForUser($adminId);

            $this->assertNotEmpty($menus);
            $this->assertTrue(collect($menus)->contains(fn ($m) => $m['code'] === 'DASHBOARD'));
            $this->assertTrue(collect($menus)->contains(fn ($m) => $m['code'] === 'INVENTORY'));
        });
    }

    public function test_dashboard_shares_menus_prop(): void
    {
        $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('menus')
                ->where('menus.0.code', 'DASHBOARD'));
    }
}
