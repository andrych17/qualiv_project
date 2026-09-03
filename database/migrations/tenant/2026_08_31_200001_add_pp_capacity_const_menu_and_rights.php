<?php

use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

/**
 * PP_SPECS.md §3F — RCCP overload threshold const (customization ladder rung 1, CLAUDE.md §2)
 * plus the PP_CAPACITY menu entry, for tenants already provisioned before this section shipped.
 * Same pattern as 2026_08_31_190001_add_pp_resource_menu_and_rights.php; SysConfigSeeder covers
 * fresh tenants.
 */
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        ConfigConst::query()->updateOrCreate(
            ['const_group' => 'PP', 'group_code' => 'CAPACITY_OVERLOAD_THRESHOLD_PCT'],
            ['seq' => 3, 'num1' => 100, 'note1' => 'Load % beyond which an RCCP row is flagged overloaded'],
        );

        $parent = ConfigMenu::query()->where('code', 'PP')->first();
        if (! $parent) {
            return;
        }

        $menu = ConfigMenu::query()->updateOrCreate(
            ['app_code' => self::APP, 'code' => 'PP_CAPACITY'],
            [
                'parent_id' => $parent->id,
                'menu_header' => $parent->menu_header,
                'menu_caption' => 'Capacity Planning (RCCP)',
                'menu_link' => '/pp/capacity-plans',
                'icon' => 'Gauge',
                'seq' => 170,
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
