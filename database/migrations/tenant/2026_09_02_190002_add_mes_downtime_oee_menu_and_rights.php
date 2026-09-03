<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

/**
 * MES module — Equipment Downtime (§3M) and OEE & Process KPIs (§3O) menu entries, added under
 * the existing MES parent menu. Same pattern as the QC/Traceability/Audit menu migration; seq
 * continues from its highest value (240) so entries don't collide.
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
            ['code' => 'MES_DOWNTIME', 'caption' => 'Equipment Downtime', 'link' => '/mes/downtime-events', 'icon' => 'AlertOctagon', 'seq' => 241],
            ['code' => 'MES_OEE', 'caption' => 'OEE & Process KPIs', 'link' => '/mes/oee', 'icon' => 'Gauge', 'seq' => 242],
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
