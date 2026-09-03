<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

/**
 * MES module — Production Orders (§3A) and Production Events (§3C) menu entries, added under
 * the existing MES parent menu. Same pattern as MES_ROUTINGS's own menu migration.
 */
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        $parent = ConfigMenu::query()->where('code', 'MES')->first();
        if (! $parent) {
            return;
        }

        $children = [
            ['code' => 'MES_PROD_ORDERS', 'caption' => 'Production Orders', 'link' => '/mes/prod-orders', 'icon' => 'ClipboardList', 'seq' => 236],
            ['code' => 'MES_PROD_EVENTS', 'caption' => 'Production Events', 'link' => '/mes/prod-events', 'icon' => 'History', 'seq' => 237],
        ];

        $adminGroup = ConfigGroup::query()->where('code', 'ADMIN')->first();
        $staffGroup = ConfigGroup::query()->where('code', 'STAFF')->first();
        $viewerGroup = ConfigGroup::query()->where('code', 'VIEWER')->first();

        foreach ($children as $row) {
            $menu = ConfigMenu::query()->updateOrCreate(
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
