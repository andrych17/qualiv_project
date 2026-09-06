<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3N Financial Analysis / Reporting — General Ledger landing page, per-account ledger drill-down (both cumulative and period-scoped), and Trial Balance (single + combined-across-companies). */
class LedgerReportsTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_general_ledger_index_lists_active_accounts_for_the_selected_company(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeAccount($company);
            $this->makeAccount($company, ['is_active' => false]);
        });

        $this->get("/accounting/general-ledger?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/GeneralLedger/Index')->has('accounts', 1));
    }

    public function test_account_ledger_shows_cumulative_running_balance_through_a_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$accountId, $periodId] = [null, null];
        $tenant->run(function () use (&$accountId, &$periodId) {
            $company = $this->makeCompany();
            $fiscalYear = $this->makeFiscalYear($company);
            $period1 = $this->firstPeriod($fiscalYear);
            $account = $this->makeAccount($company, ['normal_balance' => Account::BALANCE_DEBIT]);
            $accountId = $account->id;
            $periodId = $period1->id;

            $journal = $this->makeJournal($company, $period1, ['debit_account' => $account, 'amount' => 300]);
            app(JournalService::class)->post($journal, null);

            // A second, unposted (draft) journal must never affect the ledger.
            $this->makeJournal($company, $period1, ['debit_account' => $account, 'amount' => 999]);
        });

        $this->get("/accounting/reports/account-ledger/{$accountId}?fiscal_period_id={$periodId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Reports/AccountLedger')
                ->has('lines', 1)
                ->where('closingBalance', 300)
                ->where('closingBalanceLabel', 'Closing balance'));
    }

    /** A credit-normal account (e.g. a liability) runs its balance the opposite way round — credit increases it, debit decreases it. */
    public function test_account_ledger_running_balance_for_a_credit_normal_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$accountId, $periodId] = [null, null];
        $tenant->run(function () use (&$accountId, &$periodId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $account = $this->makeAccount($company, ['normal_balance' => Account::BALANCE_CREDIT]);
            $accountId = $account->id;
            $periodId = $period->id;

            $journal = $this->makeJournal($company, $period, ['credit_account' => $account, 'amount' => 400]);
            app(JournalService::class)->post($journal, null);
        });

        $this->get("/accounting/reports/account-ledger/{$accountId}?fiscal_period_id={$periodId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('closingBalance', 400));
    }

    public function test_account_ledger_shows_a_period_scoped_total_when_cost_center_key_is_present(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$accountId, $periodId, $costCenterId] = [null, null, null];
        $tenant->run(function () use (&$accountId, &$periodId, &$costCenterId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $account = $this->makeAccount($company, ['normal_balance' => Account::BALANCE_DEBIT]);
            $accountId = $account->id;
            $periodId = $period->id;
            $costCenter = $this->makeCostCenter($company);
            $costCenterId = $costCenter->id;

            $journal = $this->makeJournal($company, $period, ['debit_account' => $account, 'amount' => 450]);
            $journal->lines()->where('account_id', $account->id)->update(['cost_center_id' => $costCenter->id]);
            app(JournalService::class)->post($journal, null);
        });

        $this->get("/accounting/reports/account-ledger/{$accountId}?fiscal_period_id={$periodId}&cost_center_id={$costCenterId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('closingBalance', 450)->where('closingBalanceLabel', 'Period total'));

        // "Unassigned" scope: cost_center_id key present but empty — matches lines with no cost center at all.
        $this->get("/accounting/reports/account-ledger/{$accountId}?fiscal_period_id={$periodId}&cost_center_id=")->assertOk()
            ->assertInertia(fn ($page) => $page->where('closingBalance', 0));
    }

    public function test_trial_balance_reports_net_debit_and_credit_per_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $periodId] = [null, null];
        $tenant->run(function () use (&$companyId, &$periodId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;
            $debit = $this->makeAccount($company, ['normal_balance' => Account::BALANCE_DEBIT]);
            $credit = $this->makeAccount($company, ['normal_balance' => Account::BALANCE_CREDIT]);
            $journal = $this->makeJournal($company, $period, ['debit_account' => $debit, 'credit_account' => $credit, 'amount' => 1000]);
            app(JournalService::class)->post($journal, null);
        });

        $this->get("/accounting/reports/trial-balance?company_id={$companyId}&fiscal_period_id={$periodId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Reports/TrialBalance')
                ->has('report.rows', 2)
                ->where('report.totalDebit', 1000)
                ->where('report.totalCredit', 1000));
    }

    public function test_trial_balance_export_streams_a_csv(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $periodId] = [null, null];
        $tenant->run(function () use (&$companyId, &$periodId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;
            $journal = $this->makeJournal($company, $period);
            app(JournalService::class)->post($journal, null);
        });

        $response = $this->get("/accounting/reports/trial-balance/export?company_id={$companyId}&fiscal_period_id={$periodId}");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Code,Account,Debit,Credit', $response->streamedContent());
    }

    public function test_trial_balance_combined_mode_sums_matching_accounts_across_companies(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $referencePeriodId = null;
        $tenant->run(function () use (&$referencePeriodId) {
            $companyA = $this->makeCompany(['legal_name' => 'Company A']);
            $companyB = $this->makeCompany(['legal_name' => 'Company B']);

            $periodA = $this->firstPeriod($this->makeFiscalYear($companyA));
            $periodB = $this->firstPeriod($this->makeFiscalYear($companyB));
            $referencePeriodId = $periodA->id;

            // Same account_code in both companies — combined mode must sum them under one row.
            $debitA = $this->makeAccount($companyA, ['account_code' => '60000', 'normal_balance' => Account::BALANCE_DEBIT]);
            $creditA = $this->makeAccount($companyA, ['account_code' => '20000', 'normal_balance' => Account::BALANCE_CREDIT]);
            app(JournalService::class)->post($this->makeJournal($companyA, $periodA, ['debit_account' => $debitA, 'credit_account' => $creditA, 'amount' => 100]), null);

            $debitB = $this->makeAccount($companyB, ['account_code' => '60000', 'normal_balance' => Account::BALANCE_DEBIT]);
            $creditB = $this->makeAccount($companyB, ['account_code' => '20000', 'normal_balance' => Account::BALANCE_CREDIT]);
            app(JournalService::class)->post($this->makeJournal($companyB, $periodB, ['debit_account' => $debitB, 'credit_account' => $creditB, 'amount' => 250]), null);
        });

        $this->get("/accounting/reports/trial-balance?combined=1&fiscal_period_id={$referencePeriodId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('report.rows', 2)
                ->where('report.totalDebit', 350)
                ->where('report.totalCredit', 350));
    }

    public function test_trial_balance_combined_mode_skips_a_company_with_no_matching_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $referencePeriodId = null;
        $tenant->run(function () use (&$referencePeriodId) {
            $companyA = $this->makeCompany(['legal_name' => 'Company A']);
            $companyWithoutYear = $this->makeCompany(['legal_name' => 'No Fiscal Year']); // no fiscal year at all

            $periodA = $this->firstPeriod($this->makeFiscalYear($companyA));
            $referencePeriodId = $periodA->id;
            $journal = $this->makeJournal($companyA, $periodA);
            app(JournalService::class)->post($journal, null);
        });

        $this->get("/accounting/reports/trial-balance?combined=1&fiscal_period_id={$referencePeriodId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('report.rows', 2)); // just Company A's own two lines
    }

    public function test_trial_balance_shows_nothing_when_no_period_is_selected(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/reports/trial-balance?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('report', null));
    }
}
