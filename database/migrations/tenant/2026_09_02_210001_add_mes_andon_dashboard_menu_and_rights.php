<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

/**
 * MES module — Andon Board (§3R) and the three §3T dashboards (Plant/Line/Process Area) menu
 * entries, added under the existing MES parent menu. No menu entry for §3S (IoT ingestion) —
 * it's an API-only integration layer with no Vue page, gated by `module:MES` on the API route
 * instead of a trustee-checked menu link.
 *
 * NOTE: on a freshly-provisioned tenant, `SYSCONFIG.config_groups` (ADMIN/STAFF/VIEWER) does not
 * exist yet when this migration runs — this migration's own `if ($adminGroup)` guards are a
 * no-op there; MES's menu tree must also stay in sync in `SysConfigSeeder`, which this change
 * does — see the `sysconfig-seeder-is-canonical-menu-source` memory.
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
            ['code' => 'MES_ANDON', 'caption' => 'Andon Board', 'link' => '/mes/andon', 'icon' => 'AlarmClockCheck', 'seq' => 245],
            ['code' => 'MES_DASHBOARD_PLANT', 'caption' => 'Plant Dashboard', 'link' => '/mes/dashboards/plant', 'icon' => 'LayoutDashboard', 'seq' => 246],
            ['code' => 'MES_DASHBOARD_LINE', 'caption' => 'Line Dashboard', 'link' => '/mes/dashboards/line', 'icon' => 'BarChart3', 'seq' => 247],
            ['code' => 'MES_DASHBOARD_PROCESS', 'caption' => 'Process Area Dashboard', 'link' => '/mes/dashboards/process-area', 'icon' => 'FlaskConical', 'seq' => 248],
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
