<?php

namespace Tests\Feature;

use App\Modules\Config\Models\ConfigMenu;
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
}
