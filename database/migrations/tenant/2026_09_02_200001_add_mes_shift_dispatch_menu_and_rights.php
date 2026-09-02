<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

/**
 * MES module — Shift Handover (§3P) and Dispatch Queue (§3Q) menu entries, added under the
 * existing MES parent menu. Same pattern as the earlier per-module menu migrations.
 *
 * NOTE: on a freshly-provisioned tenant, `SYSCONFIG.config_groups` (ADMIN/STAFF/VIEWER) does not
 * exist yet when this migration runs — only `database/seeders/SysConfigSeeder.php` (run after
 * migrations) actually grants rights on a fresh tenant. This migration's own `if ($adminGroup)`
 * guards are a no-op there; MES's menu tree must also stay in sync in `SysConfigSeeder`, which
 * this change does (see that file's `MES` block) — see the
 * `sysconfig-seeder-is-canonical-menu-source` memory for why, discovered building §3M/§3O.
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
            ['code' => 'MES_SHIFT_HANDOVERS', 'caption' => 'Shift Handover', 'link' => '/mes/shift-handovers', 'icon' => 'ArrowLeftRight', 'seq' => 243],
            ['code' => 'MES_DISPATCH_QUEUE', 'caption' => 'Dispatch Queue', 'link' => '/mes/dispatch-queue', 'icon' => 'ListOrdered', 'seq' => 244],
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
