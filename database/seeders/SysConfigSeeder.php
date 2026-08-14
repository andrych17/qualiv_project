<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Seeder;

/**
 * Tenant SysConfig seed — groups, menus, CRUD rights, user↔group assign, consts.
 * Must run inside $tenant->run(...).
 *
 * Migration lives at database/migrations/tenant/2026_07_13_100000_create_config_tables.php
 * (SYSCONFIG.config_groups|grpusers|menus|rights|consts) — no extra menu migration needed.
 */
class SysConfigSeeder extends Seeder
{
    private const APP = 'NUSAEVO';

    /** @var list<array{code: string, descr: string}> */
    private const GROUPS = [
        ['code' => 'ADMIN', 'descr' => 'Full access administrators'],
        ['code' => 'STAFF', 'descr' => 'Operational staff — create/update day-to-day data'],
        ['code' => 'VIEWER', 'descr' => 'Read-only access'],
    ];

    /**
     * Email → group code(s). Users must already exist in the tenant DB.
     *
     * @var array<string, list<string>>
     */
    private const USER_GROUPS = [
        'admin@nusaevo.com' => ['ADMIN'],
        'staff@nusaevo.com' => ['STAFF'],
        'viewer@nusaevo.com' => ['VIEWER'],
        'andry@nusaevo.com' => ['ADMIN'],
        'tirta@nusaevo.com' => ['ADMIN'],
        'simon@nusaevo.com' => ['ADMIN'],
    ];

    public function run(): void
    {
        $groups = $this->seedGroups();
        $menus = $this->seedMenus();
        $this->seedRights($groups, $menus);
        $this->seedGroupUsers($groups);
        $this->seedConsts();
    }

    /** @return array<string, ConfigGroup> */
    private function seedGroups(): array
    {
        $map = [];
        foreach (self::GROUPS as $row) {
            $map[$row['code']] = ConfigGroup::query()->updateOrCreate(
                ['app_code' => self::APP, 'code' => $row['code']],
                [
                    'descr' => $row['descr'],
                    'status_code' => 'A',
                ],
            );
        }

        return $map;
    }

    /**
     * Flat sidebar menus. status I = placeholder (not shipped yet) — hidden from nav.
     *
     * @return array<string, ConfigMenu>
     */
    private function seedMenus(): array
    {
        $rows = [
            // Live
            ['code' => 'DASHBOARD', 'menu_header' => 'Main', 'menu_caption' => 'Dashboard', 'menu_link' => '/dashboard', 'icon' => 'LayoutDashboard', 'seq' => 10, 'status_code' => 'A'],
            ['code' => 'INVENTORY', 'menu_header' => 'Operations', 'menu_caption' => 'Inventory', 'menu_link' => '/inventory/items', 'icon' => 'Boxes', 'seq' => 70, 'status_code' => 'A'],
            ['code' => 'CONFIG_MENUS', 'menu_header' => 'System', 'menu_caption' => 'Menus', 'menu_link' => '/config/menus', 'icon' => 'Menu', 'seq' => 200, 'status_code' => 'A'],
            ['code' => 'CONFIG_GROUPS', 'menu_header' => 'System', 'menu_caption' => 'Groups', 'menu_link' => '/config/groups', 'icon' => 'Shield', 'seq' => 210, 'status_code' => 'A'],
            ['code' => 'CONFIG_USERS', 'menu_header' => 'System', 'menu_caption' => 'Users', 'menu_link' => '/config/users', 'icon' => 'UserRoundCog', 'seq' => 215, 'status_code' => 'A'],
            ['code' => 'CONFIG_CONSTS', 'menu_header' => 'System', 'menu_caption' => 'Constants', 'menu_link' => '/config/consts', 'icon' => 'SlidersHorizontal', 'seq' => 220, 'status_code' => 'A'],
            ['code' => 'CONFIG_SERIALS', 'menu_header' => 'System', 'menu_caption' => 'Serials', 'menu_link' => '/config/serials', 'icon' => 'Hash', 'seq' => 225, 'status_code' => 'A'],
            ['code' => 'DESIGN_SYSTEM', 'menu_header' => 'System', 'menu_caption' => 'Komponen UI', 'menu_link' => '/design-system', 'icon' => 'Layers', 'seq' => 230, 'status_code' => 'A'],

            // Placeholder — keep rows, status I until module ships
            ['code' => 'CRM', 'menu_header' => 'Core', 'menu_caption' => 'CRM', 'menu_link' => '#', 'icon' => 'Users', 'seq' => 20, 'status_code' => 'I'],
            ['code' => 'SCHEDULE', 'menu_header' => 'Core', 'menu_caption' => 'Schedule', 'menu_link' => '#', 'icon' => 'CalendarDays', 'seq' => 30, 'status_code' => 'I'],
            ['code' => 'WNE', 'menu_header' => 'Core', 'menu_caption' => 'Workflow & Notifications', 'menu_link' => '#', 'icon' => 'Workflow', 'seq' => 40, 'status_code' => 'I'],
            ['code' => 'LEGAL', 'menu_header' => 'Vertical', 'menu_caption' => 'Legal', 'menu_link' => '/legal/cases', 'icon' => 'Scale', 'seq' => 60, 'status_code' => 'A'],
            // Internal-only (Nusaevo's own team) — not part of any sellable plan, see config/tenant_modules.php.
            ['code' => 'PROJECTS', 'menu_header' => 'Internal', 'menu_caption' => 'Projects', 'menu_link' => '/projects', 'icon' => 'Kanban', 'seq' => 65, 'status_code' => 'A'],
            ['code' => 'SALES', 'menu_header' => 'Operations', 'menu_caption' => 'Sales', 'menu_link' => '#', 'icon' => 'ShoppingCart', 'seq' => 80, 'status_code' => 'I'],
            ['code' => 'PURCHASE', 'menu_header' => 'Operations', 'menu_caption' => 'Purchase', 'menu_link' => '#', 'icon' => 'Truck', 'seq' => 90, 'status_code' => 'I'],
            ['code' => 'ACCOUNTING', 'menu_header' => 'Operations', 'menu_caption' => 'Accounting', 'menu_link' => '#', 'icon' => 'Calculator', 'seq' => 120, 'status_code' => 'I'],
            ['code' => 'HCM', 'menu_header' => 'People', 'menu_caption' => 'HCM', 'menu_link' => '#', 'icon' => 'UserCog', 'seq' => 130, 'status_code' => 'I'],
            ['code' => 'PAYROLL', 'menu_header' => 'People', 'menu_caption' => 'Payroll', 'menu_link' => '#', 'icon' => 'Wallet', 'seq' => 140, 'status_code' => 'I'],
        ];

        $map = [];
        foreach ($rows as $row) {
            $map[$row['code']] = ConfigMenu::query()->updateOrCreate(
                ['app_code' => self::APP, 'code' => $row['code']],
                [
                    'menu_header' => $row['menu_header'],
                    'menu_caption' => $row['menu_caption'],
                    'menu_link' => $row['menu_link'],
                    'icon' => $row['icon'],
                    'seq' => $row['seq'],
                    'status_code' => $row['status_code'],
                ],
            );
        }

        return $map;
    }

    /**
     * @param  array<string, ConfigGroup>  $groups
     * @param  array<string, ConfigMenu>  $menus
     */
    private function seedRights(array $groups, array $menus): void
    {
        // ponytail: rights still seeded for placeholders so flipping status A later is enough
        $matrix = [
            'ADMIN' => array_fill_keys(array_keys($menus), 'CRUD'),
            'STAFF' => [
                'DASHBOARD' => 'R',
                'INVENTORY' => 'CRUD',
                'LEGAL' => 'CRUD',
                'PROJECTS' => 'CRUD',
                'DESIGN_SYSTEM' => 'R',
            ],
            'VIEWER' => [
                'DASHBOARD' => 'R',
                'INVENTORY' => 'R',
                'LEGAL' => 'R',
                'PROJECTS' => 'R',
                'DESIGN_SYSTEM' => 'R',
            ],
        ];

        foreach ($matrix as $groupCode => $menuTrustees) {
            $group = $groups[$groupCode];
            foreach ($menuTrustees as $menuCode => $trustee) {
                $menu = $menus[$menuCode];
                ConfigRight::query()->updateOrCreate(
                    ['group_id' => $group->id, 'menu_id' => $menu->id],
                    [
                        'group_code' => $group->code,
                        'menu_code' => $menu->code,
                        'menu_seq' => $menu->seq,
                        'trustee' => $trustee,
                        'app_code' => self::APP,
                    ],
                );
            }
        }
    }

    /** @param  array<string, ConfigGroup>  $groups */
    private function seedGroupUsers(array $groups): void
    {
        foreach (self::USER_GROUPS as $email => $groupCodes) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                continue;
            }

            foreach ($groupCodes as $groupCode) {
                $group = $groups[$groupCode];
                ConfigGroupUser::query()->updateOrCreate(
                    ['group_id' => $group->id, 'user_id' => $user->id],
                    ['group_code' => $group->code],
                );
            }
        }
    }

    private function seedConsts(): void
    {
        $consts = [
            ['const_group' => 'APP', 'group_code' => 'NAME', 'seq' => 1, 'str1' => 'NusaEvo ERP', 'note1' => 'Product display name'],
            ['const_group' => 'APP', 'group_code' => 'TZ', 'seq' => 2, 'str1' => 'Asia/Jakarta', 'note1' => 'Default timezone'],
            ['const_group' => 'INVENTORY', 'group_code' => 'LOW_STOCK', 'seq' => 1, 'num1' => 10, 'note1' => 'Default low-stock threshold'],
            ['const_group' => 'STATUS', 'group_code' => 'ACTIVE', 'seq' => 1, 'str1' => 'A', 'str2' => 'Active'],
            ['const_group' => 'STATUS', 'group_code' => 'INACTIVE', 'seq' => 2, 'str1' => 'I', 'str2' => 'Inactive'],
            ['const_group' => 'TRUSTEE', 'group_code' => 'CRUD', 'seq' => 1, 'str1' => 'CRUD', 'note1' => 'Full menu trustee'],
            ['const_group' => 'TRUSTEE', 'group_code' => 'R', 'seq' => 2, 'str1' => 'R', 'note1' => 'Read-only trustee'],
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
