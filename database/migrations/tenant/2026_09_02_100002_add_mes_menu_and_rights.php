<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

/**
 * MES module — first menu entries (MES_SPECS.md §3D Equipment Master Data). Same
 * updateOrCreate + per-group ConfigRight pattern as PP's own first-menu migration
 * (2026_08_31_150002_add_pp_menu_and_rights.php), for a brand-new top-level parent.
 */
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        $parent = ConfigMenu::query()->updateOrCreate(
            ['app_code' => self::APP, 'code' => 'MES'],
            [
                'parent_id' => null,
                'menu_header' => 'Operations',
                'menu_caption' => 'Manufacturing Execution',
                'menu_link' => '/mes/work-centers',
                'icon' => 'Factory',
                'seq' => 230,
                'status_code' => 'A',
                'module_code' => 'MES',
            ]
        );

        $children = [
            ['code' => 'MES_WORK_CENTERS', 'caption' => 'Work Centers', 'link' => '/mes/work-centers', 'icon' => 'Boxes', 'seq' => 231],
            ['code' => 'MES_MACHINES', 'caption' => 'Machines', 'link' => '/mes/machines', 'icon' => 'Cog', 'seq' => 232],
            ['code' => 'MES_STATIONS', 'caption' => 'Stations', 'link' => '/mes/stations', 'icon' => 'MapPin', 'seq' => 233],
        ];

        $adminGroup = ConfigGroup::query()->where('code', 'ADMIN')->first();
        $staffGroup = ConfigGroup::query()->where('code', 'STAFF')->first();
        $viewerGroup = ConfigGroup::query()->where('code', 'VIEWER')->first();

        $menus = [$parent];

        foreach ($children as $row) {
            $menus[] = ConfigMenu::query()->updateOrCreate(
                ['app_code' => self::APP, 'code' => $row['code']],
                [
                    'parent_id' => $parent->id,
                    'menu_header' => $parent->menu_header,
                    'menu_caption' => $row['caption'],
                    'menu_link' => $row['link'],
                    'icon' => $row['icon'],
                    'seq' => $row['seq'],
                    'status_code' => 'A',
                    'module_code' => $parent->module_code,
                ]
            );
        }

        foreach ($menus as $menu) {
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
