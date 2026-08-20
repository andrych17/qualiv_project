<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class ConfigGroupConstCrudTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_manage_group_rights_and_members(): void
    {
        $tenant = $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/config/groups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Config/Groups/Index'));

        $this->post('/config/groups', [
            'code' => 'OPS',
            'descr' => 'Ops team',
            'status_code' => 'A',
        ])->assertRedirect();

        $groupId = null;
        $menuId = null;
        $adminId = null;
        $tenant->run(function () use (&$groupId, &$menuId, &$adminId) {
            $groupId = ConfigGroup::query()->where('code', 'OPS')->value('id');
            $menuId = ConfigMenu::query()->where('code', 'INVENTORY')->value('id');
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
        });

        $this->put('/config/groups/'.$groupId, [
            'code' => 'OPS',
            'descr' => 'Ops team',
            'status_code' => 'A',
            'rights' => [
                [
                    'menu_id' => $menuId,
                    'create' => true,
                    'read' => true,
                    'update' => false,
                    'delete' => false,
                ],
            ],
            'user_ids' => [$adminId],
        ])->assertRedirect(route('config.groups.index'));

        $tenant->run(function () use ($groupId, $menuId, $adminId) {
            $this->assertSame(
                'CR',
                ConfigRight::query()->where('group_id', $groupId)->where('menu_id', $menuId)->value('trustee')
            );
            $this->assertTrue(
                ConfigGroupUser::query()->where('group_id', $groupId)->where('user_id', $adminId)->exists()
            );
        });
    }

    public function test_admin_can_crud_consts(): void
    {
        $tenant = $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/config/consts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Config/Consts/Index'));

        $this->post('/config/consts', [
            'const_group' => 'TEST',
            'group_code' => 'FLAG',
            'seq' => 1,
            'str1' => 'yes',
            'str2' => null,
            'num1' => null,
            'num2' => null,
            'note1' => 'demo',
        ])->assertRedirect(route('config.consts.index'));

        $constId = null;
        $tenant->run(function () use (&$constId) {
            $constId = ConfigConst::query()
                ->where('const_group', 'TEST')
                ->where('group_code', 'FLAG')
                ->value('id');
            $this->assertNotNull($constId);
        });

        $this->put('/config/consts/'.$constId, [
            'const_group' => 'TEST',
            'group_code' => 'FLAG',
            'seq' => 2,
            'str1' => 'no',
            'str2' => null,
            'num1' => 5,
            'num2' => null,
            'note1' => 'updated',
        ])->assertRedirect(route('config.consts.index'));

        $tenant->run(function () {
            $this->assertSame(
                'no',
                ConfigConst::query()->where('const_group', 'TEST')->where('group_code', 'FLAG')->value('str1')
            );
        });

        $this->delete('/config/consts/'.$constId)
            ->assertRedirect(route('config.consts.index'));

        $tenant->run(function () {
            $row = ConfigConst::query()->where('const_group', 'TEST')->where('group_code', 'FLAG')->first();
            $this->assertNotNull($row);
            $this->assertFalse($row->is_active);
        });
    }
}
