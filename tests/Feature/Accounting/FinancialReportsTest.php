<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3N — Balance Sheet, Profit & Loss, and the Reporting Hub landing page. Cash Flow gets its own file (CashFlowReportTest) given its size. */
class FinancialReportsTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_reporting_hub_index_renders(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $this->get('/accounting/reports')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Reports/Index'));
    }

    public function test_admin_can_view_a_single_company_balance_sheet_with_contra_asset_and_current_earnings(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $period1Id, $period2Id] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$period1Id, &$period2Id) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $fiscalYear = $this->makeFiscalYear($company);
            $period1 = $this->firstPeriod($fiscalYear);
            $period1Id = $period1->id;
            $period2Id = FiscalPeriod::query()->where('fiscal_year_id', $fiscalYear->id)->where('period_no', 2)->value('id');

            $cash = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            $accumDep = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_CREDIT]);
            $ap = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            $equity = $this->makeAccount($company, ['account_type' => Account::TYPE_EQUITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            $revenue = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE, 'normal_balance' => Account::BALANCE_CREDIT]);
            $expense = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);

            $journals = app(JournalService::class);
            $journals->post($this->makeJournal($company, $period1, ['debit_account' => $cash, 'credit_account' => $equity, 'amount' => 10000000]), $this->adminUserId());
            $journals->post($this->makeJournal($company, $period1, ['debit_account' => $cash, 'credit_account' => $revenue, 'amount' => 3000000]), $this->adminUserId());
            $journals->post($this->makeJournal($company, $period1, ['debit_account' => $expense, 'credit_account' => $cash, 'amount' => 1000000]), $this->adminUserId());
            $journals->post($this->makeJournal($company, $period1, ['debit_account' => $expense, 'credit_account' => $ap, 'amount' => 500000]), $this->adminUserId());
            $journals->post($this->makeJournal($company, $period1, ['debit_account' => $expense, 'credit_account' => $accumDep, 'amount' => 200000]), $this->adminUserId());
        });

        $this->get("/accounting/reports/balance-sheet?company_id={$companyId}&fiscal_period_id={$period1Id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Reports/BalanceSheet')
                ->where('report.current.totalAssets', 11800000)
                ->where('report.current.totalLiabilitiesAndEquity', 11800000)
                ->where('report.current.variance', 0)
                ->where('report.prior', null));

        // A contra-asset (credit-normal ASSET account) must display as a NEGATIVE line, not positive.
        $this->get("/accounting/reports/balance-sheet?company_id={$companyId}&fiscal_period_id={$period1Id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('report.current.assets', function ($assets) {
                $contra = collect($assets)->firstWhere('balance', -200000.0);

                return $contra !== null;
            }));

        // Viewing period 2 (nothing new posted) surfaces period 1 as the prior period.
        $this->get("/accounting/reports/balance-sheet?company_id={$companyId}&fiscal_period_id={$period2Id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('report.prior.totalAssets', 11800000));
    }

    public function test_balance_sheet_shows_no_report_when_the_company_has_no_fiscal_periods(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/reports/balance-sheet?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('report', null)->has('periods', 0));
    }

    public function test_balance_sheet_combined_mode_merges_companies_and_skips_one_without_a_matching_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyAId, $period2AId] = [null, null];
        $tenant->run(function () use (&$companyAId, &$period2AId) {
            $companyA = $this->makeCompany(['legal_name' => 'A']);
            $companyAId = $companyA->id;
            $fiscalYearA = $this->makeFiscalYear($companyA);
            $period1A = $this->firstPeriod($fiscalYearA);
            $period2A = FiscalPeriod::query()->where('fiscal_year_id', $fiscalYearA->id)->where('period_no', 2)->first();
            $period2AId = $period2A->id;
            $cash = $this->makeAccount($companyA, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            $equity = $this->makeAccount($companyA, ['account_type' => Account::TYPE_EQUITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            app(JournalService::class)->post($this->makeJournal($companyA, $period1A, ['debit_account' => $cash, 'credit_account' => $equity, 'amount' => 1000000]), $this->adminUserId());

            // Company B has NO fiscal year at all -> CombinedReportPeriodResolver can never find a matching period_no for it.
            $this->makeCompany(['legal_name' => 'B']);
        });

        // Viewing period 2 (nothing new posted since period 1) exercises the combined report's own hasPrior=true merge branch.
        $this->get("/accounting/reports/balance-sheet?combined=1&company_id={$companyAId}&fiscal_period_id={$period2AId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('combined', true)
                ->where('report.current.totalAssets', 1000000)
                ->where('report.prior.totalAssets', 1000000));
    }

    public function test_balance_sheet_export_streams_a_csv(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $periodId] = [null, null];
        $tenant->run(function () use (&$companyId, &$periodId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;
            $cash = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            $equity = $this->makeAccount($company, ['account_type' => Account::TYPE_EQUITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            app(JournalService::class)->post($this->makeJournal($company, $period, ['debit_account' => $cash, 'credit_account' => $equity, 'amount' => 500000]), $this->adminUserId());
        });

        $response = $this->get("/accounting/reports/balance-sheet/export?company_id={$companyId}&fiscal_period_id={$periodId}");
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Total Assets', $csv);
        $this->assertStringContainsString('500000', $csv);
    }

    public function test_admin_can_view_a_single_company_profit_and_loss_with_a_prior_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $period1Id, $period2Id] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$period1Id, &$period2Id) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $fiscalYear = $this->makeFiscalYear($company);
            $period1 = $this->firstPeriod($fiscalYear);
            $period1Id = $period1->id;
            $period2 = FiscalPeriod::query()->where('fiscal_year_id', $fiscalYear->id)->where('period_no', 2)->first();
            $period2Id = $period2->id;

            $cash = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            $revenue = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE, 'normal_balance' => Account::BALANCE_CREDIT]);
            $cogs = $this->makeAccount($company, ['account_type' => Account::TYPE_COGS, 'normal_balance' => Account::BALANCE_DEBIT]);
            $expense = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);

            $journals = app(JournalService::class);
            // Period 1: revenue 5,000,000, cogs 2,000,000, expense 1,000,000 -> gross profit 3,000,000, net income 2,000,000.
            $journals->post($this->makeJournal($company, $period1, ['debit_account' => $cash, 'credit_account' => $revenue, 'amount' => 5000000]), $this->adminUserId());
            $journals->post($this->makeJournal($company, $period1, ['debit_account' => $cogs, 'credit_account' => $cash, 'amount' => 2000000]), $this->adminUserId());
            $journals->post($this->makeJournal($company, $period1, ['debit_account' => $expense, 'credit_account' => $cash, 'amount' => 1000000]), $this->adminUserId());

            // Period 2: a smaller revenue-only period, to have distinct current-vs-prior figures.
            $journals->post($this->makeJournal($company, $period2, ['debit_account' => $cash, 'credit_account' => $revenue, 'amount' => 1000000]), $this->adminUserId());
        });

        $this->get("/accounting/reports/profit-loss?company_id={$companyId}&fiscal_period_id={$period2Id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Reports/ProfitLoss')
                ->where('report.current.totalRevenue', 1000000)
                ->where('report.current.netIncome', 1000000)
                ->where('report.prior.totalRevenue', 5000000)
                ->where('report.prior.grossProfit', 3000000)
                ->where('report.prior.netIncome', 2000000));
    }

    public function test_profit_loss_combined_mode_merges_companies_and_skips_one_without_a_matching_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyAId, $period2AId] = [null, null];
        $tenant->run(function () use (&$companyAId, &$period2AId) {
            $companyA = $this->makeCompany(['legal_name' => 'A']);
            $companyAId = $companyA->id;
            $fiscalYearA = $this->makeFiscalYear($companyA);
            $period1A = $this->firstPeriod($fiscalYearA);
            $period2A = FiscalPeriod::query()->where('fiscal_year_id', $fiscalYearA->id)->where('period_no', 2)->first();
            $period2AId = $period2A->id;
            $cash = $this->makeAccount($companyA, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            $revenue = $this->makeAccount($companyA, ['account_type' => Account::TYPE_REVENUE, 'normal_balance' => Account::BALANCE_CREDIT]);
            $journals = app(JournalService::class);
            $journals->post($this->makeJournal($companyA, $period1A, ['debit_account' => $cash, 'credit_account' => $revenue, 'amount' => 750000]), $this->adminUserId());
            $journals->post($this->makeJournal($companyA, $period2A, ['debit_account' => $cash, 'credit_account' => $revenue, 'amount' => 250000]), $this->adminUserId());

            $this->makeCompany(['legal_name' => 'B']);
        });

        // Viewing period 2 exercises the combined report's own hasPrior=true merge branch (period 1's activity).
        $this->get("/accounting/reports/profit-loss?combined=1&company_id={$companyAId}&fiscal_period_id={$period2AId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('report.current.totalRevenue', 250000)
                ->where('report.current.netIncome', 250000)
                ->where('report.prior.totalRevenue', 750000)
                ->where('report.prior.netIncome', 750000));
    }

    public function test_profit_loss_export_streams_a_csv(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $periodId] = [null, null];
        $tenant->run(function () use (&$companyId, &$periodId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;
            $cash = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            $revenue = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE, 'normal_balance' => Account::BALANCE_CREDIT]);
            app(JournalService::class)->post($this->makeJournal($company, $period, ['debit_account' => $cash, 'credit_account' => $revenue, 'amount' => 300000]), $this->adminUserId());
        });

        $response = $this->get("/accounting/reports/profit-loss/export?company_id={$companyId}&fiscal_period_id={$periodId}");
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Net Income', $csv);
        $this->assertStringContainsString('300000', $csv);
    }
}
