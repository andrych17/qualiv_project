<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class ActiveSidebarMenuTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_all_active_sidebar_menus_are_functional(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $projectId = null;
        $tenant->run(function () use (&$companyId, &$projectId) {
            $company = $this->makeCompany();
            $companyId = $company->id;

            $project = Project::create([
                'name' => 'Qualiv.id Platform Launch',
                'code' => 'QLV',
                'status' => 'active',
                'description' => 'Test project',
            ]);
            $projectId = $project->id;
        });

        $routesToTest = [
            // Main
            '/dashboard' => 'Dashboard',
            '/projects' => 'Projects/Projects/Index',
            '/projects/create' => 'Projects/Projects/Create',
            "/projects/{$projectId}" => 'Projects/Projects/Show',

            // Keuangan - Pemasukan & Pengeluaran
            '/accounting/ar-invoices' => 'Accounting/ArInvoices/Index',
            '/accounting/ar-invoices/create' => 'Accounting/ArInvoices/Create',
            '/accounting/ar-payments' => 'Accounting/ArPayments/Index',
            '/accounting/ar-payments/create' => 'Accounting/ArPayments/Create',
            '/accounting/ap-bills' => 'Accounting/ApBills/Index',
            '/accounting/ap-bills/create' => 'Accounting/ApBills/Create',
            '/accounting/ap-payments' => 'Accounting/ApPayments/Index',
            '/accounting/ap-payments/create' => 'Accounting/ApPayments/Create',
            '/accounting/bank-accounts' => 'Accounting/BankAccounts/Index',
            '/accounting/bank-accounts/create' => 'Accounting/BankAccounts/Create',
            '/accounting/cash-transfers/create' => 'Accounting/CashTransfers/Create',
            '/accounting/recurring-journal-templates' => 'Accounting/RecurringJournalTemplates/Index',
            '/accounting/recurring-journal-templates/create' => 'Accounting/RecurringJournalTemplates/Create',

            // Keuangan - Pembukuan
            '/accounting/accounts' => 'Accounting/Accounts/Index',
            '/accounting/accounts/create' => 'Accounting/Accounts/Create',
            '/accounting/journals' => 'Accounting/Journals/Index',
            '/accounting/journals/create' => 'Accounting/Journals/Create',
            '/accounting/general-ledger' => 'Accounting/GeneralLedger/Index',
            '/accounting/tax-periods' => 'Accounting/TaxPeriods/Index',

            // Keuangan - Reports
            '/accounting/reports' => 'Accounting/Reports/Index',
            '/accounting/reports/profit-loss' => 'Accounting/Reports/ProfitLoss',
            '/accounting/reports/balance-sheet' => 'Accounting/Reports/BalanceSheet',
            '/accounting/reports/cash-flow' => 'Accounting/Reports/CashFlow',
            '/accounting/reports/trial-balance' => 'Accounting/Reports/TrialBalance',

            // System - Config
            '/config/users' => 'Config/Users/Index',
            '/config/groups' => 'Config/Groups/Index',
            '/config/menus' => 'Config/Menus/Index',
            '/config/modules' => 'Config/Modules/Index',
            '/config/serials' => 'Config/Serials/Index',
            '/config/theme' => 'Config/Theme/Index',
            '/config/consts' => 'Config/Consts/Index',
            '/config/fields' => 'Config/Fields/Index',
            '/design-system' => 'DesignSystem/Index',
        ];

        foreach ($routesToTest as $uri => $expectedComponent) {
            $response = $this->get($uri);
            $response->assertOk();
            $response->assertInertia(fn ($page) => $page->component($expectedComponent));
        }
    }
}
