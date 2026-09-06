<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Services\AssetDisposalService;
use App\Modules\Accounting\Services\DepreciationRunService;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * §3N — Cash Flow Statement (indirect method). The heaviest report to fixture: needs a real
 * cash (BankAccount) movement to tie `actualCashChange` out against the derived `netChange`,
 * plus a fixed asset addition/depreciation pair and a same-period disposal to exercise the
 * disposal-journal-exclusion logic documented on CashFlowService.
 */
class CashFlowReportTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_cash_flow_ties_out_across_operating_investing_and_financing(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $periodId] = [null, null];
        $tenant->run(function () use (&$companyId, &$periodId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;

            $cash = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $cash->id]);

            $equity = $this->makeAccount($company, ['account_type' => Account::TYPE_EQUITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            $revenue = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE, 'normal_balance' => Account::BALANCE_CREDIT]);
            $expense = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);
            $assetGlAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            $accumDepAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_CREDIT]);
            $depExpenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);

            $asset = $this->makeFixedAsset($company, [
                'asset_gl_account_id' => $assetGlAccount->id,
                'accumulated_depreciation_gl_account_id' => $accumDepAccount->id,
                'depreciation_expense_gl_account_id' => $depExpenseAccount->id,
            ]);

            $journals = app(JournalService::class);
            // Owner invests cash -> financing.
            $journals->post($this->makeJournal($company, $period, ['debit_account' => $cash, 'credit_account' => $equity, 'amount' => 5000000]), $this->adminUserId());
            // Cash revenue -> operating (via net income).
            $journals->post($this->makeJournal($company, $period, ['debit_account' => $cash, 'credit_account' => $revenue, 'amount' => 2000000]), $this->adminUserId());
            // Cash rent expense -> operating (via net income).
            $journals->post($this->makeJournal($company, $period, ['debit_account' => $expense, 'credit_account' => $cash, 'amount' => 500000]), $this->adminUserId());
            // A cash purchase of fixed-asset-account activity this period -> investing (assetAdditions).
            $journals->post($this->makeJournal($company, $period, ['debit_account' => $assetGlAccount, 'credit_account' => $cash, 'amount' => 1000000]), $this->adminUserId());

            // Monthly depreciation -> operating add-back (non-cash).
            app(DepreciationRunService::class)->runForAssets(collect([$asset]), $period, $this->adminUserId());
        });

        // netIncome = 2,000,000 - 500,000 - 250,000(depreciation) = 1,250,000.
        // operatingTotal = 1,250,000 + 250,000(add-back) = 1,500,000.
        // investingTotal = 0(proceeds) - 1,000,000(additions) = -1,000,000.
        // financingTotal = 5,000,000. netChange = 5,500,000 = actualCashChange -> variance 0.
        $this->get("/accounting/reports/cash-flow?company_id={$companyId}&fiscal_period_id={$periodId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Reports/CashFlow')
                ->where('report.netIncome', 1250000)
                ->where('report.depreciationAddBack', 250000)
                ->where('report.operatingTotal', 1500000)
                ->where('report.assetAdditions', 1000000)
                ->where('report.investingTotal', -1000000)
                ->where('report.financingTotal', 5000000)
                ->where('report.netChange', 5500000)
                ->where('report.actualCashChange', 5500000)
                ->where('report.variance', 0));
    }

    /** The disposal's own journal (crediting the asset account, debiting accumulated depreciation) must NOT be double-counted as this period's own addition/add-back. */
    public function test_cash_flow_excludes_a_disposals_own_journal_from_addback_and_additions(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $periodId] = [null, null];
        $tenant->run(function () use (&$companyId, &$periodId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;

            $assetGlAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            $accumDepAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_CREDIT]);
            $depExpenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);
            $gainLossAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);
            $proceedsAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);

            $asset = $this->makeFixedAsset($company, [
                'asset_gl_account_id' => $assetGlAccount->id,
                'accumulated_depreciation_gl_account_id' => $accumDepAccount->id,
                'depreciation_expense_gl_account_id' => $depExpenseAccount->id,
            ]);

            // The routine monthly depreciation run — NOT the disposal's own catch-up.
            app(DepreciationRunService::class)->runForAssets(collect([$asset]), $period, $this->adminUserId());

            app(AssetDisposalService::class)->dispose($asset->fresh(), [
                'disposal_date' => $period->start_date->toDateString(),
                'proceeds' => 100000,
                'proceeds_gl_account_id' => $proceedsAccount->id,
                'gain_loss_gl_account_id' => $gainLossAccount->id,
            ], $this->adminUserId());
        });

        $this->get("/accounting/reports/cash-flow?company_id={$companyId}&fiscal_period_id={$periodId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('report.depreciationAddBack', 250000)
                ->where('report.assetAdditions', 0)
                ->where('report.disposalProceeds', 100000)
                // NBV at disposal = 12,000,000 - 250,000 = 11,750,000; proceeds 100,000 -> loss of -11,650,000; reversal is the positive of that.
                ->where('report.disposalGainLossReversal', 11650000));
    }

    public function test_cash_flow_combined_mode_sums_across_companies_and_skips_one_without_a_matching_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyAId, $periodAId] = [null, null];
        $tenant->run(function () use (&$companyAId, &$periodAId) {
            $companyA = $this->makeCompany(['legal_name' => 'A']);
            $companyAId = $companyA->id;
            $periodA = $this->firstPeriod($this->makeFiscalYear($companyA));
            $periodAId = $periodA->id;
            $cash = $this->makeAccount($companyA, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            BankAccount::query()->create(['company_id' => $companyA->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $cash->id]);
            $equity = $this->makeAccount($companyA, ['account_type' => Account::TYPE_EQUITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            app(JournalService::class)->post($this->makeJournal($companyA, $periodA, ['debit_account' => $cash, 'credit_account' => $equity, 'amount' => 400000]), $this->adminUserId());

            $this->makeCompany(['legal_name' => 'B']);
        });

        $this->get("/accounting/reports/cash-flow?combined=1&company_id={$companyAId}&fiscal_period_id={$periodAId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('report.financingTotal', 400000)->where('report.netChange', 400000));
    }

    public function test_cash_flow_export_streams_a_csv(): void
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
            app(JournalService::class)->post($this->makeJournal($company, $period, ['debit_account' => $cash, 'credit_account' => $equity, 'amount' => 250000]), $this->adminUserId());
        });

        $response = $this->get("/accounting/reports/cash-flow/export?company_id={$companyId}&fiscal_period_id={$periodId}");
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Net change in cash', $csv);
        $this->assertStringContainsString('250000', $csv);
    }

    public function test_cash_flow_shows_no_report_when_the_company_has_no_fiscal_periods(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/reports/cash-flow?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('report', null));
    }
}
