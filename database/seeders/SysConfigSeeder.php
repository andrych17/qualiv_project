<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Config\Models\ConfigConst;
use App\Modules\Config\Models\ConfigGroup;
use App\Modules\Config\Models\ConfigGroupUser;
use App\Modules\Config\Models\ConfigMenu;
use App\Modules\Config\Models\ConfigRight;
use Illuminate\Database\Seeder;

/**
 * Tenant SysConfig seed — Admin group, sidebar menus, CRUD rights, sample consts.
 * Must run inside $tenant->run(...).
 */
class SysConfigSeeder extends Seeder
{
    private const APP = 'NUSAEVO';

    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();

        $group = ConfigGroup::query()->updateOrCreate(
            ['app_code' => self::APP, 'code' => 'ADMIN'],
            [
                'descr' => 'Full access administrators',
                'status_code' => 'A',
            ],
        );

        ConfigGroupUser::query()->updateOrCreate(
            ['group_id' => $group->id, 'user_id' => $admin->id],
            ['group_code' => $group->code],
        );

        $menus = [
            ['code' => 'DASHBOARD', 'menu_caption' => 'Dashboard', 'menu_link' => '/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 10],
            ['code' => 'CRM', 'menu_caption' => 'CRM', 'menu_link' => '#', 'icon' => 'Users', 'seq' => 20],
            ['code' => 'SCHEDULE', 'menu_caption' => 'Schedule', 'menu_link' => '#', 'icon' => 'CalendarDays', 'seq' => 30],
            ['code' => 'LEGAL', 'menu_caption' => 'Legal', 'menu_link' => '#', 'icon' => 'Scale', 'seq' => 40],
            ['code' => 'INVENTORY', 'menu_caption' => 'Inventory', 'menu_link' => '/inventory/items', 'icon' => 'Boxes', 'seq' => 50],
            ['code' => 'WORKFLOW', 'menu_caption' => 'Workflow', 'menu_link' => '#', 'icon' => 'Workflow', 'seq' => 60],
            ['code' => 'NOTIFICATIONS', 'menu_caption' => 'Notifications', 'menu_link' => '#', 'icon' => 'Bell', 'seq' => 70],
        ];

        foreach ($menus as $row) {
            $menu = ConfigMenu::query()->updateOrCreate(
                ['app_code' => self::APP, 'code' => $row['code']],
                [
                    'menu_header' => 'Main',
                    'menu_caption' => $row['menu_caption'],
                    'menu_link' => $row['menu_link'],
                    'icon' => $row['icon'],
                    'seq' => $row['seq'],
                    'status_code' => 'A',
                ],
            );

            ConfigRight::query()->updateOrCreate(
                ['group_id' => $group->id, 'menu_id' => $menu->id],
                [
                    'group_code' => $group->code,
                    'menu_code' => $menu->code,
                    'menu_seq' => $menu->seq,
                    'trustee' => 'CRUD',
                    'app_code' => self::APP,
                ],
            );
        }

        $consts = [
            ['const_group' => 'APP', 'group_code' => 'NAME', 'seq' => 1, 'str1' => 'NusaEvo ERP', 'note1' => 'Product display name'],
            ['const_group' => 'APP', 'group_code' => 'TZ', 'seq' => 2, 'str1' => 'Asia/Jakarta', 'note1' => 'Default timezone'],
            ['const_group' => 'INVENTORY', 'group_code' => 'LOW_STOCK', 'seq' => 1, 'num1' => 10, 'note1' => 'Default low-stock threshold'],
            ['const_group' => 'STATUS', 'group_code' => 'ACTIVE', 'seq' => 1, 'str1' => 'A', 'str2' => 'Active'],
            ['const_group' => 'STATUS', 'group_code' => 'INACTIVE', 'seq' => 2, 'str1' => 'I', 'str2' => 'Inactive'],
        ];

        foreach ($consts as $c) {
            ConfigConst::query()->updateOrCreate(
                [
                    'const_group' => $c['const_group'],
                    'group_code' => $c['group_code'],
                ],
                [
                    'seq' => $c['seq'],
                    'str1' => $c['str1'] ?? null,
                    'str2' => $c['str2'] ?? null,
                    'num1' => $c['num1'] ?? null,
                    'note1' => $c['note1'] ?? null,
                ],
            );
        }
    }
}
