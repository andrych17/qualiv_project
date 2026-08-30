<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

// Opportunities was seeded as a CRM submenu (CRM_OPPORTUNITIES, /crm/opportunities) by
// 2026_08_29_113000, but the feature was actually built under Sales
// (app/Modules/Sales/Controllers/OpportunityController.php, sales.opportunities.* routes) —
// /crm/opportunities 404s. Move the menu entry to Sales instead of editing the earlier
// (already-run) migration.
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        $oldMenu = ConfigMenu::query()->where(['app_code' => self::APP, 'code' => 'CRM_OPPORTUNITIES'])->first();
        if ($oldMenu) {
            ConfigRight::query()->where('menu_id', $oldMenu->id)->delete();
            $oldMenu->delete();
        }

        $salesItems = [
            ['code' => 'SALES_OPPORTUNITIES', 'caption' => 'Opportunity Management', 'link' => '/sales/opportunities', 'icon' => 'Target', 'seq' => 82],
            ['code' => 'SALES_QUOTATIONS', 'caption' => 'Quotations', 'link' => '/sales/quotations', 'icon' => 'FileText', 'seq' => 83],
            ['code' => 'SALES_ORDERS', 'caption' => 'Sales Orders', 'link' => '/sales/orders', 'icon' => 'ShoppingBag', 'seq' => 84],
            ['code' => 'SALES_DELIVERIES', 'caption' => 'Deliveries', 'link' => '/sales/deliveries', 'icon' => 'Truck', 'seq' => 85],
            ['code' => 'SALES_INVOICES', 'caption' => 'Invoices', 'link' => '/sales/invoices', 'icon' => 'Receipt', 'seq' => 86],
            ['code' => 'SALES_PROFILES', 'caption' => 'Customer Profiles', 'link' => '/sales/customer-profiles', 'icon' => 'UserCheck', 'seq' => 87],
        ];

        $parent = ConfigMenu::query()->where(['app_code' => self::APP, 'code' => 'SALES'])->first();
        if (! $parent) {
            return;
        }

        $adminGroup = ConfigGroup::query()->where('code', 'ADMIN')->first();
        $staffGroup = ConfigGroup::query()->where('code', 'STAFF')->first();
        $viewerGroup = ConfigGroup::query()->where('code', 'VIEWER')->first();

        foreach ($salesItems as $item) {
            $menu = ConfigMenu::query()->updateOrCreate(
                ['app_code' => self::APP, 'code' => $item['code']],
                [
                    'parent_id' => $parent->id,
                    'menu_header' => $parent->menu_header,
                    'menu_caption' => $item['caption'],
                    'menu_link' => $item['link'],
                    'icon' => $item['icon'],
                    'seq' => $item['seq'],
                    'status_code' => 'A',
                    'module_code' => $parent->module_code,
                ]
            );

            if ($item['code'] !== 'SALES_OPPORTUNITIES') {
                continue;
            }

            if ($adminGroup) {
                ConfigRight::query()->updateOrCreate(
                    ['group_id' => $adminGroup->id, 'menu_id' => $menu->id],
                    ['app_code' => self::APP, 'group_code' => 'ADMIN', 'menu_code' => $menu->code, 'trustee' => 'CRUD']
                );
            }

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
