<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

/**
 * POS module — menus and trustee permissions (§3U, §5).
 */
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        $parent = ConfigMenu::query()->updateOrCreate(
            ['app_code' => self::APP, 'code' => 'POS'],
            [
                'parent_id' => null,
                'menu_header' => 'Operations',
                'menu_caption' => 'Point of Sale',
                'menu_link' => '/pos/sale',
                'icon' => 'Store',
                'seq' => 240,
                'status_code' => 'A',
                'module_code' => 'POS',
            ]
        );

        $children = [
            ['code' => 'POS_SALE', 'caption' => 'Register / Cashier', 'link' => '/pos/sale', 'icon' => 'ShoppingCart', 'seq' => 241],
            ['code' => 'POS_SESSION', 'caption' => 'Cash Shifts / Sessions', 'link' => '/pos/sessions', 'icon' => 'Clock', 'seq' => 242],
            ['code' => 'POS_TERMINAL', 'caption' => 'Terminals & Devices', 'link' => '/pos/terminals', 'icon' => 'Monitor', 'seq' => 243],
            ['code' => 'POS_PROFILE', 'caption' => 'POS Profiles', 'link' => '/pos/profiles', 'icon' => 'Sliders', 'seq' => 244],
            ['code' => 'POS_FLOOR', 'caption' => 'Floor & Tables', 'link' => '/pos/floors', 'icon' => 'LayoutGrid', 'seq' => 245],
            ['code' => 'POS_KDS', 'caption' => 'Kitchen Display (KDS)', 'link' => '/pos/kds', 'icon' => 'UtensilsCrossed', 'seq' => 246],
            ['code' => 'POS_RETURN', 'caption' => 'Returns & Refunds', 'link' => '/pos/returns', 'icon' => 'RotateCcw', 'seq' => 247],
            ['code' => 'POS_REPORTS', 'caption' => 'POS Reports & Analytics', 'link' => '/pos/reports', 'icon' => 'BarChart3', 'seq' => 248],
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
