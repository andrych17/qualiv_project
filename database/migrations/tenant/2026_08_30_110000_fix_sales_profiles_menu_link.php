<?php

use App\Modules\SysConfig\Models\ConfigMenu;
use Illuminate\Database\Migrations\Migration;

// SALES_PROFILES was seeded pointing at /sales/customer-profiles (2026_08_29_113000), but
// CustomerProfileController's routes actually live under the "master" prefix
// (sales.master.customers.* -> /sales/master/customers, see Sales/Routes/web.php) — the
// seeded link never matched a real route. Same class of typo'd-link bug as CRM_CUSTOMERS
// (fixed by 2026_08_30_100000); here the code/caption were already right, only the link was
// wrong, so a straight in-place update is enough — no rights to re-seed.
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        ConfigMenu::query()
            ->where(['app_code' => self::APP, 'code' => 'SALES_PROFILES'])
            ->update(['menu_link' => '/sales/master/customers']);
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
