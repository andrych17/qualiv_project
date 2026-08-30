<?php

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

// CRM_CUSTOMERS (/crm/customers) was seeded by 2026_08_29_113000 but no such page/route
// exists — CRM has no "Customer" concept of its own (CRM_SPECS.md §3B/§3C: only Contacts and
// Companies; "Customer" is a Partner role, surfaced on the Sales side via
// SALES.customer_sales_profiles, not a CRM page). CompanyController/§3C's own page
// (app/Modules/CRM/Controllers/CompanyController.php, crm.companies.* routes) has existed
// since CRM shipped but was never given a sidebar entry. Replace the broken entry rather
// than editing the earlier (already-run) migration.
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        $oldMenu = ConfigMenu::query()->where(['app_code' => self::APP, 'code' => 'CRM_CUSTOMERS'])->first();
        if ($oldMenu) {
            ConfigRight::query()->where('menu_id', $oldMenu->id)->delete();
            $oldMenu->delete();
        }

        $parent = ConfigMenu::query()->where(['app_code' => self::APP, 'code' => 'CRM'])->first();
        if (! $parent) {
            return;
        }

        $menu = ConfigMenu::query()->updateOrCreate(
            ['app_code' => self::APP, 'code' => 'CRM_COMPANIES'],
            [
                'parent_id' => $parent->id,
                'menu_header' => $parent->menu_header,
                'menu_caption' => 'Companies',
                'menu_link' => '/crm/companies',
                'icon' => 'Building',
                'seq' => 24,
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
            $parentTrustee = ConfigRight::query()
                ->where('group_id', $staffGroup->id)
                ->where('menu_code', $parent->code)
                ->value('trustee') ?: 'CRUD';

            ConfigRight::query()->updateOrCreate(
                ['group_id' => $staffGroup->id, 'menu_id' => $menu->id],
                ['app_code' => self::APP, 'group_code' => 'STAFF', 'menu_code' => $menu->code, 'trustee' => $parentTrustee]
            );
        }

        if ($viewerGroup) {
            $parentTrustee = ConfigRight::query()
                ->where('group_id', $viewerGroup->id)
                ->where('menu_code', $parent->code)
                ->value('trustee') ?: 'R';

            ConfigRight::query()->updateOrCreate(
                ['group_id' => $viewerGroup->id, 'menu_id' => $menu->id],
                ['app_code' => self::APP, 'group_code' => 'VIEWER', 'menu_code' => $menu->code, 'trustee' => $parentTrustee]
            );
        }
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
