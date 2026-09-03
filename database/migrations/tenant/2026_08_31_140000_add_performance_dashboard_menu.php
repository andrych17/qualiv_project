<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

// PERFORMANCE never got a Dashboard sidebar entry — DashboardController + Performance/Dashboard.vue
// (§3A: KPI/OKR/Budget/Scorecard rollup, needs-attention, recent achievements) were already
// fully built and routed at /performance/dashboard (PERFORMANCE_SPECS.md §3A,
// Performance/Routes/web.php), just never added to the menu. Same
// updateOrCreate + per-group ConfigRight pattern as 2026_08_29_113000_patch_nested_submenus_and_rights,
// for the one new item this time rather than a whole module's menu.
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        $parent = ConfigMenu::query()->where('code', 'PERFORMANCE')->first();
        if (! $parent) {
            return;
        }

        $menu = ConfigMenu::query()->updateOrCreate(
            ['app_code' => self::APP, 'code' => 'PERFORMANCE_DASHBOARD'],
            [
                'parent_id' => $parent->id,
                'menu_header' => $parent->menu_header,
                'menu_caption' => 'Dashboard',
                'menu_link' => '/performance/dashboard',
                'icon' => 'LayoutDashboard',
                'seq' => 150,
                'status_code' => 'A',
                'module_code' => $parent->module_code,
            ]
        );

        $adminGroup = ConfigGroup::query()->where('code', 'ADMIN')->first();
        if ($adminGroup) {
            ConfigRight::query()->updateOrCreate(
                ['group_id' => $adminGroup->id, 'menu_id' => $menu->id],
                ['app_code' => self::APP, 'group_code' => 'ADMIN', 'menu_code' => $menu->code, 'trustee' => 'CRUD']
            );
        }

        $staffGroup = ConfigGroup::query()->where('code', 'STAFF')->first();
        if ($staffGroup) {
            $parentTrustee = ConfigRight::query()
                ->where('group_id', $staffGroup->id)
                ->where('menu_code', $parent->code)
                ->value('trustee') ?: 'CRU';

            ConfigRight::query()->updateOrCreate(
                ['group_id' => $staffGroup->id, 'menu_id' => $menu->id],
                ['app_code' => self::APP, 'group_code' => 'STAFF', 'menu_code' => $menu->code, 'trustee' => $parentTrustee]
            );
        }

        $viewerGroup = ConfigGroup::query()->where('code', 'VIEWER')->first();
        if ($viewerGroup) {
            ConfigRight::query()->updateOrCreate(
                ['group_id' => $viewerGroup->id, 'menu_id' => $menu->id],
                ['app_code' => self::APP, 'group_code' => 'VIEWER', 'menu_code' => $menu->code, 'trustee' => 'R']
            );
        }
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
