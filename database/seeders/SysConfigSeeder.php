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
            // menu_link points at §3B Product Master (INVENTORY.* schema); legacy
            // /inventory/items (public schema, CLAUDE.md §7A) stays routable but off the
            // sidebar during the transition.
            ['code' => 'INVENTORY', 'menu_header' => 'Operations', 'menu_caption' => 'Inventory', 'menu_link' => '/inventory/products', 'icon' => 'Boxes', 'seq' => 70, 'status_code' => 'A'],
            ['code' => 'CONFIG_MENUS', 'menu_header' => 'System', 'menu_caption' => 'Menus', 'menu_link' => '/config/menus', 'icon' => 'Menu', 'seq' => 200, 'status_code' => 'A'],
            ['code' => 'CONFIG_GROUPS', 'menu_header' => 'System', 'menu_caption' => 'Groups', 'menu_link' => '/config/groups', 'icon' => 'Shield', 'seq' => 210, 'status_code' => 'A'],
            ['code' => 'CONFIG_USERS', 'menu_header' => 'System', 'menu_caption' => 'Users', 'menu_link' => '/config/users', 'icon' => 'UserRoundCog', 'seq' => 215, 'status_code' => 'A'],
            ['code' => 'CONFIG_MODULES', 'menu_header' => 'System', 'menu_caption' => 'Modules', 'menu_link' => '/config/modules', 'icon' => 'AppWindow', 'seq' => 218, 'status_code' => 'A'],
            ['code' => 'CONFIG_CONSTS', 'menu_header' => 'System', 'menu_caption' => 'Constants', 'menu_link' => '/config/consts', 'icon' => 'SlidersHorizontal', 'seq' => 220, 'status_code' => 'A'],
            ['code' => 'CONFIG_FIELDS', 'menu_header' => 'System', 'menu_caption' => 'Custom Fields', 'menu_link' => '/config/fields', 'icon' => 'ListPlus', 'seq' => 222, 'status_code' => 'A'],
            ['code' => 'CONFIG_SERIALS', 'menu_header' => 'System', 'menu_caption' => 'Serials', 'menu_link' => '/config/serials', 'icon' => 'Hash', 'seq' => 225, 'status_code' => 'A'],
            ['code' => 'CONFIG_THEME', 'menu_header' => 'System', 'menu_caption' => 'Theme', 'menu_link' => '/config/theme', 'icon' => 'Palette', 'seq' => 228, 'status_code' => 'A'],
            ['code' => 'DESIGN_SYSTEM', 'menu_header' => 'System', 'menu_caption' => 'Komponen UI', 'menu_link' => '/design-system', 'icon' => 'Layers', 'seq' => 230, 'status_code' => 'A'],
            // CRM §3A-§3G shipped — all reached via the in-page sub-nav on /crm/dashboard,
            // not separate sidebar rows (one menu code covers all of them, see
            // CRM_SPECS.md §5). §3A Dashboard is now the section landing page.
            ['code' => 'CRM', 'menu_header' => 'Core', 'menu_caption' => 'CRM', 'menu_link' => '/crm/dashboard', 'icon' => 'Users', 'seq' => 20, 'status_code' => 'A'],
            // §3G Merge — admin-only (menu.perm:CRM_MERGE below grants only ADMIN, per this
            // seeder's array_fill_keys(...) for the ADMIN matrix; STAFF/VIEWER's matrices
            // simply never list it). status_code I so it never appears as its own sidebar
            // row — it's reached from the CRM sub-nav (permission-gated client-side via
            // the shared `canMergePartners` Inertia prop) and the route itself is the real
            // gate regardless of what the UI shows.
            ['code' => 'CRM_MERGE', 'menu_header' => 'Core', 'menu_caption' => 'CRM — Merge', 'menu_link' => '/crm/merge', 'icon' => 'Merge', 'seq' => 21, 'status_code' => 'I'],

            // §3A shipped — the calendar dashboard is now the section landing page.
            ['code' => 'SCHEDULE', 'menu_header' => 'Core', 'menu_caption' => 'Schedule', 'menu_link' => '/schedule/dashboard', 'icon' => 'CalendarDays', 'seq' => 30, 'status_code' => 'A'],
            // §3B shipped (definition builder); §3A dashboard doesn't exist yet, so the
            // workflow list is the landing page for now — same "point straight at the built
            // page" convention Legal used before it had a dashboard either.
            ['code' => 'WNE', 'menu_header' => 'Core', 'menu_caption' => 'Workflow & Notifications', 'menu_link' => '/wne/dashboard', 'icon' => 'Workflow', 'seq' => 40, 'status_code' => 'A'],
            // §3A shipped — the Main Dashboard is the only page so far, so it's also the
            // section landing page (same "point straight at the built page" convention WNE
            // used before §3B). Future sub-pages (3B upload form, 3C version viewer, ...)
            // reuse this one menu code, same "one code covers every sub-page" convention.
            ['code' => 'DMS', 'menu_header' => 'Core', 'menu_caption' => 'Documents', 'menu_link' => '/dms/dashboard', 'icon' => 'FolderOpen', 'seq' => 45, 'status_code' => 'A'],
            ['code' => 'LEGAL', 'menu_header' => 'Vertical', 'menu_caption' => 'Legal', 'menu_link' => '/legal/matters', 'icon' => 'Scale', 'seq' => 60, 'status_code' => 'A'],
            // Internal-only (Nusaevo's own team) — not part of any sellable plan, see config/tenant_modules.php.
            ['code' => 'PROJECTS', 'menu_header' => 'Internal', 'menu_caption' => 'Projects', 'menu_link' => '/projects', 'icon' => 'Kanban', 'seq' => 65, 'status_code' => 'A'],
            ['code' => 'SALES', 'menu_header' => 'Operations', 'menu_caption' => 'Sales', 'menu_link' => '/sales/dashboard', 'icon' => 'ShoppingCart', 'seq' => 80, 'status_code' => 'A'],
            ['code' => 'PURCHASE', 'menu_header' => 'Operations', 'menu_caption' => 'Purchase', 'menu_link' => '/purchase/dashboard', 'icon' => 'Truck', 'seq' => 90, 'status_code' => 'A'],
            // §3B ships (COA/GL setup) — Accounts is the section landing page for now
            // (no §3A dashboard yet), same "point straight at the built page" convention
            // WNE/DMS used before their own dashboards existed.
            ['code' => 'ACCOUNTING', 'menu_header' => 'Operations', 'menu_caption' => 'Accounting', 'menu_link' => '/accounting/accounts', 'icon' => 'Calculator', 'seq' => 120, 'status_code' => 'A'],
            ['code' => 'HCM', 'menu_header' => 'People', 'menu_caption' => 'HCM', 'menu_link' => '/hcm/dashboard', 'icon' => 'UserCog', 'seq' => 130, 'status_code' => 'A'],
            ['code' => 'PAYROLL', 'menu_header' => 'People', 'menu_caption' => 'Payroll', 'menu_link' => '/payroll/dashboard', 'icon' => 'Wallet', 'seq' => 140, 'status_code' => 'A'],
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
                    'module_code' => (str_starts_with($row['code'], 'CONFIG_') || in_array($row['code'], ['DASHBOARD', 'DESIGN_SYSTEM'], true))
                        ? null
                        : $row['code'],
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
                'CRM' => 'CRUD',
                'SCHEDULE' => 'CRUD',
                'WNE' => 'CRUD',
                'DMS' => 'CRUD',
                'PROJECTS' => 'CRUD',
                'ACCOUNTING' => 'CRUD',
                'HCM' => 'CRUD',
                'PAYROLL' => 'CRUD',
                'PURCHASE' => 'CRUD',
                'SALES' => 'CRUD',
                'DESIGN_SYSTEM' => 'R',
                'CONFIG_THEME' => 'CRUD',
            ],
            'VIEWER' => [
                'DASHBOARD' => 'R',
                'INVENTORY' => 'R',
                'LEGAL' => 'R',
                'CRM' => 'R',
                'SCHEDULE' => 'R',
                'WNE' => 'R',
                'DMS' => 'R',
                'PROJECTS' => 'R',
                'ACCOUNTING' => 'R',
                'HCM' => 'R',
                'PAYROLL' => 'R',
                'PURCHASE' => 'R',
                'SALES' => 'R',
                'DESIGN_SYSTEM' => 'R',
                'CONFIG_THEME' => 'CRUD',
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
            // §3B: tenant default costing method, overridable per product — customization
            // ladder rung 1, never hardcoded (CLAUDE.md §2).
            ['const_group' => 'INVENTORY', 'group_code' => 'DEFAULT_COSTING_METHOD', 'seq' => 2, 'str1' => 'fifo', 'note1' => 'fifo | average — default for new products'],
            // §3L: a batch is "expiring soon" inside this many days — tenant-editable since a
            // pharma-adjacent tenant wants a longer lead time than a food-adjacent one.
            ['const_group' => 'INVENTORY', 'group_code' => 'BATCH_EXPIRY_WARNING_DAYS', 'seq' => 3, 'num1' => 30, 'note1' => 'Days before expiry a batch surfaces as "expiring soon"'],
            // §3N: how long an unfulfilled reservation holds stock before the auto-release
            // sweep frees it — a caller can override per-reservation via `expires_at`.
            ['const_group' => 'INVENTORY', 'group_code' => 'RESERVATION_EXPIRY_HOURS', 'seq' => 4, 'num1' => 24, 'note1' => 'Hours an unfulfilled reservation holds stock before auto-release'],
            // ACCOUNTING §3M due-date rules — tenant-editable per CLAUDE.md §2 customization
            // ladder rung 1 (constants), never hardcoded, since regulation can change.
            ['const_group' => 'ACCOUNTING_TAX', 'group_code' => 'PPN_DUE_DAY_OF_MONTH', 'seq' => 1, 'num1' => 0, 'note1' => 'SPT Masa PPN due date: day of the following month (0 = last day)'],
            ['const_group' => 'ACCOUNTING_TAX', 'group_code' => 'PPH_DUE_DAY_OF_MONTH', 'seq' => 2, 'num1' => 10, 'note1' => 'PPh withholding remittance due date: day of the following month'],
            ['const_group' => 'THEME', 'group_code' => 'ACTIVE_THEME', 'seq' => 1, 'str1' => 'classic-navy', 'note1' => 'Active UI theme key for tenant'],
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
