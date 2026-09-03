<?php

use App\Modules\SysConfig\Models\ConfigMenu;
use Illuminate\Database\Migrations\Migration;

// PAYROLL_STRUCTURES was seeded pointing at /payroll/salary-structures (2026_08_29_113000),
// but SalaryStructureController's actual route is /payroll/structures (Payroll/Routes/web.php).
// Same class of typo'd-link bug as PURCHASE_SPEND/ACCOUNTING_TRIAL_BALANCE/HCM_DEPARTMENTS;
// code/caption were already right, only the link was wrong, so an in-place update is enough —
// no rights to re-seed.
//
// PAYROLL_PAYSLIPS's seeded link (/payroll/payslips) was correct all along — PayslipController
// only ever had a show() route (keyed by a specific payroll_run_line), no index; the sidebar
// entry had nowhere valid to land. PayslipController::index() now fills that gap, so it needs
// no menu_link patch here.
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        ConfigMenu::query()
            ->where(['app_code' => self::APP, 'code' => 'PAYROLL_STRUCTURES'])
            ->update(['menu_link' => '/payroll/structures']);
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
