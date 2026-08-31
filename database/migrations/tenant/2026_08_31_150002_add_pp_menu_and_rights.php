<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

/**
 * PP module — first menu entry (PP_SPECS.md §3A Item Planning Parameters). Same
 * updateOrCreate + per-group ConfigRight pattern as
 * 2026_08_29_113000_patch_nested_submenus_and_rights.php, but for a brand-new
 * top-level parent (no existing PP menu row for tenants provisioned before this module).
 */
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        $parent = ConfigMenu::query()->updateOrCreate(
            ['app_code' => self::APP, 'code' => 'PP'],
            [
                'parent_id' => null,
                'menu_header' => 'Operations',
                'menu_caption' => 'Production Planning',
                'menu_link' => '/pp/item-planning-params',
                'icon' => 'CalendarRange',
                'seq' => 160,
                'status_code' => 'A',
                'module_code' => 'PP',
            ]
        );

        $child = ConfigMenu::query()->updateOrCreate(
            ['app_code' => self::APP, 'code' => 'PP_ITEM_PARAMS'],
            [
                'parent_id' => $parent->id,
                'menu_header' => $parent->menu_header,
                'menu_caption' => 'Item Planning Parameters',
                'menu_link' => '/pp/item-planning-params',
                'icon' => 'SlidersHorizontal',
                'seq' => 161,
                'status_code' => 'A',
                'module_code' => $parent->module_code,
            ]
        );

        $adminGroup = ConfigGroup::query()->where('code', 'ADMIN')->first();
        $staffGroup = ConfigGroup::query()->where('code', 'STAFF')->first();
        $viewerGroup = ConfigGroup::query()->where('code', 'VIEWER')->first();

        foreach ([$parent, $child] as $menu) {
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
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
