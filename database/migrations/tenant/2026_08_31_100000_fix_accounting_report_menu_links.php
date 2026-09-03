<?php

use App\Modules\SysConfig\Models\ConfigMenu;
use Illuminate\Database\Migrations\Migration;

// ACCOUNTING_TRIAL_BALANCE/BALANCE_SHEET/INCOME_STMT/CASH_FLOW were seeded pointing at
// /accounting/trial-balance, /accounting/balance-sheet, /accounting/income-statement, and
// /accounting/cash-flow (2026_08_29_113000), but TrialBalanceController, BalanceSheetController,
// ProfitLossController, and CashFlowController's actual routes all live nested under
// /accounting/reports/* (see Accounting/Routes/web.php's "§3N Financial Analysis / Reporting"
// section). Same class of typo'd-link bug as DMS_CATEGORIES/CRM_CUSTOMERS/SALES_PROFILES/
// PURCHASE_SPEND; code/caption were already right, only the link was wrong, so an in-place
// update is enough — no rights to re-seed.
//
// ACCOUNTING_GL's seeded link (/accounting/general-ledger) was correct all along — the page
// just didn't exist yet; GeneralLedgerController + its route now fill that gap, so it needs
// no menu_link patch here.
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    private const FIXES = [
        'ACCOUNTING_TRIAL_BALANCE' => '/accounting/reports/trial-balance',
        'ACCOUNTING_BALANCE_SHEET' => '/accounting/reports/balance-sheet',
        'ACCOUNTING_INCOME_STMT' => '/accounting/reports/profit-loss',
        'ACCOUNTING_CASH_FLOW' => '/accounting/reports/cash-flow',
    ];

    public function up(): void
    {
        foreach (self::FIXES as $code => $link) {
            ConfigMenu::query()
                ->where(['app_code' => self::APP, 'code' => $code])
                ->update(['menu_link' => $link]);
        }
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
