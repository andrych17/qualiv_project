<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use App\Modules\SysConfig\Services\ConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class SysConfigSeederTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_sysconfig_seed_gives_admin_menus_groups_and_consts(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $this->assertGreaterThanOrEqual(10, ConfigMenu::query()->count());
            $this->assertSame(3, ConfigGroup::query()->count());
            $this->assertNotNull(
                ConfigConst::query()->where('const_group', 'APP')->where('group_code', 'NAME')->first()
            );

            $adminId = (int) User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $this->assertTrue(
                ConfigGroupUser::query()
                    ->where('user_id', $adminId)
                    ->where('group_code', 'ADMIN')
                    ->exists()
            );

            $menus = app(ConfigService::class)->menusForUser($adminId);
            $this->assertNotEmpty($menus);
            $this->assertTrue(collect($menus)->contains(fn ($m) => $m['code'] === 'DASHBOARD'));
            $this->assertTrue(collect($menus)->contains(fn ($m) => $m['code'] === 'INVENTORY'));
            // LEGAL menu is active in seed but hidden unless tenant plan enables LEGAL
            $this->assertFalse(collect($menus)->contains(fn ($m) => $m['code'] === 'CRM'));

            $adminRight = ConfigRight::query()
                ->where('group_code', 'ADMIN')
                ->where('menu_code', 'INVENTORY')
                ->value('trustee');
            $this->assertSame('CRUD', $adminRight);
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
                ->has('navMenus')
                ->has('cards')
                ->has('activities')
                ->has('firm')
                ->where('navMenus.0.code', 'DASHBOARD'));
    }
}
