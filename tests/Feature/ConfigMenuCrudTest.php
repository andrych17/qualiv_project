<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Services\ConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class ConfigMenuCrudTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_list_and_create_menu(): void
    {
        $tenant = $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/config/menus')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Config/Menus/Index')
                ->has('items.data'));

        $this->post('/config/menus', [
            'code' => 'TEST_MENU',
            'menu_caption' => 'Test Menu',
            'menu_header' => 'System',
            'menu_link' => '/test',
            'icon' => 'Settings',
            'parent_id' => null,
            'seq' => 999,
            'status_code' => 'A',
        ])->assertRedirect(route('config.menus.index'));

        $tenant->run(function () {
            $this->assertNotNull(ConfigMenu::query()->where('code', 'TEST_MENU')->first());
        });
    }

    public function test_admin_can_update_and_delete_menu(): void
    {
        $tenant = $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $menuId = null;
        $tenant->run(function () use (&$menuId) {
            $menuId = ConfigMenu::query()->where('code', 'INVENTORY')->value('id');
        });

        $this->put('/config/menus/'.$menuId, [
            'code' => 'INVENTORY',
            'menu_caption' => 'Inventory Updated',
            'menu_header' => 'Operations',
            'menu_link' => '/inventory/items',
            'icon' => 'Boxes',
            'parent_id' => null,
            'seq' => 70,
            'status_code' => 'A',
        ])->assertRedirect(route('config.menus.index'));

        $tenant->run(function () {
            $this->assertSame(
                'Inventory Updated',
                ConfigMenu::query()->where('code', 'INVENTORY')->value('menu_caption')
            );
        });

        $this->delete('/config/menus/'.$menuId)
            ->assertRedirect(route('config.menus.index'));

        $tenant->run(function () {
            $this->assertNull(ConfigMenu::query()->where('code', 'INVENTORY')->first());
        });
    }

    public function test_menu_hierarchy_allows_up_to_3_levels_and_rejects_level_4(): void
    {
        $tenant = $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $l1Id = null;
        $l2Id = null;
        $l3Id = null;

        // Level 1
        $this->post('/config/menus', [
            'code' => 'LVL_1',
            'menu_caption' => 'Level 1 Menu',
            'menu_header' => 'Operations',
            'menu_link' => '#',
            'seq' => 100,
            'status_code' => 'A',
        ])->assertRedirect(route('config.menus.index'));

        $tenant->run(function () use (&$l1Id) {
            $l1Id = ConfigMenu::query()->where('code', 'LVL_1')->value('id');
        });

        // Level 2 (child of Level 1)
        $this->post('/config/menus', [
            'code' => 'LVL_2',
            'menu_caption' => 'Level 2 Submenu',
            'menu_header' => 'Operations',
            'menu_link' => '#',
            'parent_id' => $l1Id,
            'seq' => 101,
            'status_code' => 'A',
        ])->assertRedirect(route('config.menus.index'));

        $tenant->run(function () use (&$l2Id) {
            $l2Id = ConfigMenu::query()->where('code', 'LVL_2')->value('id');
        });

        // Level 3 (child of Level 2)
        $this->post('/config/menus', [
            'code' => 'LVL_3',
            'menu_caption' => 'Level 3 Item',
            'menu_header' => 'Operations',
            'menu_link' => '/lvl3',
            'parent_id' => $l2Id,
            'seq' => 102,
            'status_code' => 'A',
        ])->assertRedirect(route('config.menus.index'));

        $tenant->run(function () use (&$l3Id) {
            $l3Id = ConfigMenu::query()->where('code', 'LVL_3')->value('id');
        });

        // Level 4 (child of Level 3) -> should FAIL validation (max 3 levels)
        $this->post('/config/menus', [
            'code' => 'LVL_4',
            'menu_caption' => 'Level 4 Item',
            'menu_header' => 'Operations',
            'menu_link' => '/lvl4',
            'parent_id' => $l3Id,
            'seq' => 103,
            'status_code' => 'A',
        ])->assertSessionHasErrors(['parent_id']);

        // Verify menusForUser generates 3-level tree
        $tenant->run(function () {
            $adminUser = User::query()->where('email', 'admin@nusaevo.com')->first();
            $menus = app(ConfigService::class)->menusForUser((int) $adminUser->id);

            $lvl1 = collect($menus)->firstWhere('code', 'LVL_1');
            $this->assertNotNull($lvl1);
            $this->assertCount(1, $lvl1['children']);

            $lvl2 = $lvl1['children'][0];
            $this->assertSame('LVL_2', $lvl2['code']);
            $this->assertCount(1, $lvl2['children']);

            $lvl3 = $lvl2['children'][0];
            $this->assertSame('LVL_3', $lvl3['code']);
            $this->assertSame('/lvl3', $lvl3['href']);
        });
    }
}
