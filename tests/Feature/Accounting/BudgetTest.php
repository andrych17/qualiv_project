<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3J Budgeting — one flat annual budget per company/fiscal year, edited one cost-center scope at a time. */
class BudgetTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_index_creates_the_budget_on_first_visit_and_saving_the_grid_replaces_the_scope(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $fiscalYearId, $accountId, $periodId, $costCenterId] = [null, null, null, null, null];
        $tenant->run(function () use (&$companyId, &$fiscalYearId, &$accountId, &$periodId, &$costCenterId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $fiscalYear = $this->makeFiscalYear($company);
            $fiscalYearId = $fiscalYear->id;
            $accountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $periodId = $this->firstPeriod($fiscalYear)->id;
            $costCenterId = $this->makeCostCenter($company)->id;
        });

        $this->get("/accounting/budgets?company_id={$companyId}&fiscal_year_id={$fiscalYearId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Budgets/Grid')->has('grid.periods', 12));

        $budgetId = null;
        $tenant->run(function () use (&$budgetId, $companyId, $fiscalYearId) {
            $budgetId = Budget::query()->where('company_id', $companyId)->where('fiscal_year_id', $fiscalYearId)->value('id');
        });
        $this->assertNotNull($budgetId);

        // Re-visiting doesn't create a second budget row (getOrCreate).
        $this->get("/accounting/budgets?company_id={$companyId}&fiscal_year_id={$fiscalYearId}")->assertOk();
        $tenant->run(function () use ($companyId, $fiscalYearId) {
            $this->assertSame(1, Budget::query()->where('company_id', $companyId)->where('fiscal_year_id', $fiscalYearId)->count());
        });

        $this->post("/accounting/budgets/{$budgetId}/grid", [
            'cost_center_id' => $costCenterId,
            'cells' => [['account_id' => $accountId, 'fiscal_period_id' => $periodId, 'amount' => 5000000]],
        ])->assertRedirect();

        $tenant->run(function () use ($budgetId, $costCenterId) {
            $this->assertSame(1, BudgetLine::query()->where('budget_id', $budgetId)->where('cost_center_id', $costCenterId)->count());
        });

        // Saving again with an empty cell list for the same scope deletes the line (real delete, not a leftover zero row).
        $this->post("/accounting/budgets/{$budgetId}/grid", ['cost_center_id' => $costCenterId, 'cells' => []])->assertRedirect();
        $tenant->run(function () use ($budgetId, $costCenterId) {
            $this->assertSame(0, BudgetLine::query()->where('budget_id', $budgetId)->where('cost_center_id', $costCenterId)->count());
        });
    }

    public function test_save_grid_rejects_invalid_cost_center_account_and_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$budgetId, $otherCompanyAccountId] = [null, null];
        $tenant->run(function () use (&$budgetId, &$otherCompanyAccountId) {
            $company = $this->makeCompany();
            $fiscalYear = $this->makeFiscalYear($company);
            $budgetId = app(BudgetService::class)->getOrCreate($company, $fiscalYear, $this->adminUserId())->id;

            $otherCompany = $this->makeCompany(['legal_name' => 'Other']);
            $otherCompanyAccountId = $this->makeAccount($otherCompany)->id;
        });

        $this->post("/accounting/budgets/{$budgetId}/grid", [
            'cost_center_id' => 999999,
            'cells' => [['account_id' => $otherCompanyAccountId, 'fiscal_period_id' => 999999, 'amount' => 100]],
        ])->assertSessionHasErrors(['cost_center_id', 'cells.0.account_id', 'cells.0.fiscal_period_id']);
    }

    public function test_admin_can_import_a_budget_csv(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$budgetId, $accountCode, $costCenterCode] = [null, null, null];
        $tenant->run(function () use (&$budgetId, &$accountCode, &$costCenterCode) {
            $company = $this->makeCompany();
            $fiscalYear = $this->makeFiscalYear($company);
            $budgetId = app(BudgetService::class)->getOrCreate($company, $fiscalYear, $this->adminUserId())->id;
            $account = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'account_code' => '60000']);
            $accountCode = $account->account_code;
            $costCenterCode = $this->makeCostCenter($company, ['code' => 'CC-1'])->code;
        });

        $csv = "account_code,cost_center_code,period_no,amount\n{$accountCode},{$costCenterCode},1,1000000\n{$accountCode},,2,2000000\n";
        $file = UploadedFile::fake()->createWithContent('budget.csv', $csv);

        $this->post("/accounting/budgets/{$budgetId}/import", ['file' => $file])->assertRedirect();

        $tenant->run(function () use ($budgetId) {
            $this->assertSame(2, BudgetLine::query()->where('budget_id', $budgetId)->count());
            $this->assertSame(1, BudgetLine::query()->where('budget_id', $budgetId)->whereNotNull('cost_center_id')->count());
            $this->assertSame(1, BudgetLine::query()->where('budget_id', $budgetId)->whereNull('cost_center_id')->count());
        });

        // Re-importing the same row updates it in place (updateOrCreate), not a duplicate.
        $csvUpdated = "account_code,cost_center_code,period_no,amount\n{$accountCode},{$costCenterCode},1,1500000\n";
        $fileUpdated = UploadedFile::fake()->createWithContent('budget2.csv', $csvUpdated);
        $this->post("/accounting/budgets/{$budgetId}/import", ['file' => $fileUpdated])->assertRedirect();

        $tenant->run(function () use ($budgetId) {
            $this->assertSame(2, BudgetLine::query()->where('budget_id', $budgetId)->count());
            $line = BudgetLine::query()->where('budget_id', $budgetId)->whereNotNull('cost_center_id')->first();
            $this->assertEqualsWithDelta(1500000.0, (float) $line->amount, 0.01);
        });
    }

    public function test_import_csv_skips_a_genuinely_blank_row(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$budgetId, $accountCode] = [null, null];
        $tenant->run(function () use (&$budgetId, &$accountCode) {
            $company = $this->makeCompany();
            $fiscalYear = $this->makeFiscalYear($company);
            $budgetId = app(BudgetService::class)->getOrCreate($company, $fiscalYear, $this->adminUserId())->id;
            $accountCode = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->account_code;
        });

        $csv = "account_code,cost_center_code,period_no,amount\n{$accountCode},,1,1000000\n\n{$accountCode},,2,2000000\n";
        $file = UploadedFile::fake()->createWithContent('blank_row.csv', $csv);

        $this->post("/accounting/budgets/{$budgetId}/import", ['file' => $file])->assertRedirect();

        $tenant->run(function () use ($budgetId) {
            $this->assertSame(2, BudgetLine::query()->where('budget_id', $budgetId)->count());
        });
    }

    public function test_import_csv_rejects_unknown_codes_and_reports_every_error_at_once(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $budgetId = null;
        $tenant->run(function () use (&$budgetId) {
            $company = $this->makeCompany();
            $fiscalYear = $this->makeFiscalYear($company);
            $budgetId = app(BudgetService::class)->getOrCreate($company, $fiscalYear, $this->adminUserId())->id;
        });

        $csv = "account_code,cost_center_code,period_no,amount\nUNKNOWN,,1,1000\nUNKNOWN2,BADCC,99,notanumber\n";
        $file = UploadedFile::fake()->createWithContent('bad_budget.csv', $csv);

        $tenant->run(function () use ($budgetId, $file) {
            $budget = Budget::query()->find($budgetId);

            try {
                app(BudgetService::class)->importCsv($budget, $file, $this->adminUserId());
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $errors = $e->errors()['file'];
                $this->assertGreaterThanOrEqual(4, count($errors));
            }

            $this->assertSame(0, BudgetLine::query()->where('budget_id', $budgetId)->count());
        });
    }

    public function test_import_csv_rejects_a_cost_center_from_a_different_fiscal_year(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$budgetId, $accountCode] = [null, null];
        $tenant->run(function () use (&$budgetId, &$accountCode) {
            $company = $this->makeCompany();
            $fiscalYear = $this->makeFiscalYear($company, ['year' => 2026]);
            $budgetId = app(BudgetService::class)->getOrCreate($company, $fiscalYear, $this->adminUserId())->id;
            $accountCode = $this->makeAccount($company)->account_code;
        });

        // period_no 13 doesn't exist for any fiscal year (only 1-12 are ever generated).
        $csv = "account_code,cost_center_code,period_no,amount\n{$accountCode},,13,1000\n";
        $file = UploadedFile::fake()->createWithContent('bad_period.csv', $csv);

        $this->post("/accounting/budgets/{$budgetId}/import", ['file' => $file])->assertSessionHasErrors(['file']);
    }

    public function test_budget_vs_actual_report_shows_variance(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $fiscalYearId] = [null, null];
        $tenant->run(function () use (&$companyId, &$fiscalYearId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $fiscalYear = $this->makeFiscalYear($company);
            $fiscalYearId = $fiscalYear->id;
            $period = $this->firstPeriod($fiscalYear);
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);

            $budget = app(BudgetService::class)->getOrCreate($company, $fiscalYear, $this->adminUserId());
            app(BudgetService::class)->saveGrid($budget, null, [['account_id' => $expenseAccount->id, 'fiscal_period_id' => $period->id, 'amount' => 1000000]], $this->adminUserId());

            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            $journal = $this->makeJournal($company, $period, ['debit_account' => $expenseAccount, 'credit_account' => $offsetAccount, 'amount' => 1200000]);
            app(JournalService::class)->post($journal, $this->adminUserId());
        });

        // Two rows: the budgeted expense account, plus the offset (liability) account, which
        // has no budget but did have actual activity (an unbudgeted actual, still reported).
        $this->get("/accounting/reports/budget-vs-actual?company_id={$companyId}&fiscal_year_id={$fiscalYearId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Reports/BudgetVsActual')
                ->has('rows', 2)
                ->where('rows.0.budget', 1000000)
                ->where('rows.0.actual', 1200000)
                ->where('rows.0.variance', 200000)
                ->where('rows.0.variance_pct', 20)
                ->where('rows.1.budget', 0)
                ->where('rows.1.actual', 1200000)
                ->where('rows.1.variance_pct', null));
    }

    public function test_budget_vs_actual_shows_an_unbudgeted_actual_with_a_null_percentage(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $fiscalYearId] = [null, null];
        $tenant->run(function () use (&$companyId, &$fiscalYearId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $fiscalYear = $this->makeFiscalYear($company);
            $fiscalYearId = $fiscalYear->id;
            $period = $this->firstPeriod($fiscalYear);
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT]);

            // No budget row created at all for this fiscal year — an unbudgeted actual.
            $journal = $this->makeJournal($company, $period, ['debit_account' => $expenseAccount, 'credit_account' => $offsetAccount, 'amount' => 300000]);
            app(JournalService::class)->post($journal, $this->adminUserId());
        });

        // Both the expense account and its offset show up — neither has a budget line.
        $this->get("/accounting/reports/budget-vs-actual?company_id={$companyId}&fiscal_year_id={$fiscalYearId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 2)
                ->where('rows.0.budget', 0)
                ->where('rows.0.actual', 300000)
                ->where('rows.0.variance_pct', null)
                ->where('rows.1.budget', 0)
                ->where('rows.1.actual', 300000)
                ->where('rows.1.variance_pct', null));
    }

    public function test_budget_vs_actual_shows_nothing_when_no_fiscal_year_is_selected(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/reports/budget-vs-actual?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('rows', []));
    }
}
