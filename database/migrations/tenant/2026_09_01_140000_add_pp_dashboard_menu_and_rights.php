<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

/**
 * PP_SPECS.md §3O — the PP_DASHBOARD menu entry, for tenants already provisioned before this
 * section shipped, plus repointing the PP parent menu's own link at the new dashboard (it used
 * to point at Item Planning Parameters — the first-built page — before an overview page existed;
 * same "point straight at the built page" convention `SysConfigSeeder` documents for Performance).
 * Same pattern as 2026_09_01_130001_add_pp_changeover_matrix_menu_and_rights.php.
 */
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        $parent = ConfigMenu::query()->where('code', 'PP')->first();
        if (! $parent) {
            return;
        }

        $parent->update(['menu_link' => '/pp/dashboard']);

        $menu = ConfigMenu::query()->updateOrCreate(
            ['app_code' => self::APP, 'code' => 'PP_DASHBOARD'],
            [
                'parent_id' => $parent->id,
                'menu_header' => $parent->menu_header,
                'menu_caption' => 'Dashboard',
                'menu_link' => '/pp/dashboard',
                'icon' => 'LayoutDashboard',
                'seq' => 160,
                'status_code' => 'A',
                'module_code' => $parent->module_code,
            ]
        );

        $adminGroup = ConfigGroup::query()->where('code', 'ADMIN')->first();
        $staffGroup = ConfigGroup::query()->where('code', 'STAFF')->first();
        $viewerGroup = ConfigGroup::query()->where('code', 'VIEWER')->first();

        if ($adminGroup) {
            ConfigRight::query()->updateOrCreate(
                ['group_id' => $adminGroup->id, 'menu_id' => $menu->id],
                ['app_code' => self::APP, 'group_code' => 'ADMIN', 'menu_code' => $menu->code, 'trustee' => 'CRUD']
            );
        }
        if ($staffGroup) {
            ConfigRight::query()->updateOrCreate(
                ['group_id' => $staffGroup->id, 'menu_id' => $menu->id],
                ['app_code' => self::APP, 'group_code' => 'STAFF', 'menu_code' => $menu->code, 'trustee' => 'CRUD']
            );
        }
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
