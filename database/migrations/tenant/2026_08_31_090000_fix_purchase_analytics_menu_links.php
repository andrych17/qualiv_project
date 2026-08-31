<?php

use App\Modules\SysConfig\Models\ConfigMenu;
use Illuminate\Database\Migrations\Migration;

// PURCHASE_SPEND/PURCHASE_ESG were seeded pointing at /purchase/spend-analytics and
// /purchase/esg (2026_08_29_113000), but AnalyticsController's actual routes live at
// purchase.analytics.spend -> /purchase/analytics/spend and purchase.analytics.esg ->
// /purchase/analytics/esg (see Purchase/Routes/web.php's "§3J Spend Analytics & §3M ESG
// Tracking" section). Same class of typo'd-link bug as DMS_CATEGORIES/CRM_CUSTOMERS/
// SALES_PROFILES; code/caption were already right, only the link was wrong, so an in-place
// update is enough — no rights to re-seed.
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        ConfigMenu::query()
            ->where(['app_code' => self::APP, 'code' => 'PURCHASE_SPEND'])
            ->update(['menu_link' => '/purchase/analytics/spend']);

        ConfigMenu::query()
            ->where(['app_code' => self::APP, 'code' => 'PURCHASE_ESG'])
            ->update(['menu_link' => '/purchase/analytics/esg']);
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
