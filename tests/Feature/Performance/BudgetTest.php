<?php

namespace Tests\Feature\Performance;

use App\Modules\Accounting\Models\Account;
use App\Modules\Performance\Models\Budget;
use App\Modules\Performance\Models\BudgetActual;
use App\Modules\Performance\Models\BudgetCategoryAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpPerformance;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3B Budgeting — draft-only mutability, status ladder, non-destructive versioning, manual/GL actual entry. */
class BudgetTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpPerformance;
    use SetsUpTenant;

    public function test_admin_can_create_a_budget_with_lines(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $periodId = null;
        $tenant->run(function () use (&$periodId) {
            $periodId = $this->makePeriod()->id;
        });

        $this->get('/performance/budgets')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/Budgets/Index'));

        $this->post('/performance/budgets', [
            'name' => 'FY2026 Budget',
            'subject_type' => Budget::SUBJECT_COMPANY,
            'fiscal_year' => 2026,
            'lines' => [
                ['category' => 'Marketing', 'period_id' => $periodId, 'amount_planned' => 15000000],
                ['category' => 'R&D', 'period_id' => $periodId, 'amount_planned' => 5000000],
            ],
        ])->assertRedirect(route('performance.budgets.index'));

        $tenant->run(function () {
            $budget = Budget::query()->where('name', 'FY2026 Budget')->first();
            $this->assertNotNull($budget);
            $this->assertSame(Budget::STATUS_DRAFT, $budget->status);
            $this->assertSame(1, $budget->version_no);
            $this->assertSame(2, $budget->lines()->count());
        });
    }

    public function test_store_rejects_invalid_subject_and_line_period(): void
    {
        $this->loginAsPerformanceAdmin();

        $this->post('/performance/budgets', [
            'name' => 'Bad Budget',
            'subject_type' => Budget::SUBJECT_ORG_UNIT,
            'subject_id' => 999999,
            'fiscal_year' => 2026,
            'lines' => [['category' => 'X', 'period_id' => 999999, 'amount_planned' => 100]],
        ])->assertSessionHasErrors(['subject_id', 'lines.0.period_id']);
    }

    public function test_only_a_draft_budget_can_be_updated_or_deleted(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $budgetId = null;
        $tenant->run(function () use (&$budgetId) {
            $budget = $this->makeBudget();
            $this->makeBudgetLine($budget, $this->makePeriod());
            $budgetId = $budget->id;
        });

        $this->patch("/performance/budgets/{$budgetId}/submit")->assertRedirect(route('performance.budgets.edit', $budgetId));
        $tenant->run(function () use ($budgetId) {
            $this->assertSame(Budget::STATUS_SUBMITTED, Budget::query()->find($budgetId)->status);
        });

        $this->put("/performance/budgets/{$budgetId}", ['name' => 'Renamed', 'subject_type' => Budget::SUBJECT_COMPANY, 'fiscal_year' => 2026])
            ->assertSessionHasErrors(['status']);

        $this->delete("/performance/budgets/{$budgetId}")->assertSessionHasErrors(['status']);
    }

    public function test_submit_requires_at_least_one_line(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $budgetId = null;
        $tenant->run(function () use (&$budgetId) {
            $budgetId = $this->makeBudget()->id;
        });

        $this->patch("/performance/budgets/{$budgetId}/submit")->assertSessionHasErrors(['lines']);
    }

    public function test_full_status_ladder_and_out_of_order_transitions_are_rejected(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $budgetId = null;
        $tenant->run(function () use (&$budgetId) {
            $budget = $this->makeBudget();
            $this->makeBudgetLine($budget, $this->makePeriod());
            $budgetId = $budget->id;
        });

        // Can't approve or lock before submitting.
        $this->patch("/performance/budgets/{$budgetId}/approve")->assertSessionHasErrors(['status']);
        $this->patch("/performance/budgets/{$budgetId}/lock")->assertSessionHasErrors(['status']);

        $this->patch("/performance/budgets/{$budgetId}/submit")->assertRedirect();
        $this->patch("/performance/budgets/{$budgetId}/submit")->assertSessionHasErrors(['status']); // already submitted

        $this->patch("/performance/budgets/{$budgetId}/approve")->assertRedirect();
        $tenant->run(function () use ($budgetId) {
            $this->assertSame(Budget::STATUS_APPROVED, Budget::query()->find($budgetId)->status);
        });

        $this->patch("/performance/budgets/{$budgetId}/lock")->assertRedirect();
        $tenant->run(function () use ($budgetId) {
            $this->assertSame(Budget::STATUS_LOCKED, Budget::query()->find($budgetId)->status);
        });
    }

    public function test_new_version_clones_header_and_lines_but_not_actuals(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $budgetId = null;
        $tenant->run(function () use (&$budgetId) {
            $budget = $this->makeBudget(['status' => Budget::STATUS_APPROVED]);
            $line = $this->makeBudgetLine($budget, $this->makePeriod(), ['category' => 'Ops', 'amount_planned' => 500]);
            $this->makeBudgetActual($line, 480);
            $budgetId = $budget->id;
        });

        $this->post("/performance/budgets/{$budgetId}/new-version")->assertRedirect();

        $tenant->run(function () use ($budgetId) {
            $original = Budget::query()->find($budgetId);
            $newVersion = Budget::query()->where('prior_version_id', $budgetId)->first();

            $this->assertNotNull($newVersion);
            $this->assertSame(Budget::STATUS_DRAFT, $newVersion->status);
            $this->assertSame(2, $newVersion->version_no);
            $this->assertSame(1, $newVersion->lines()->count());
            $this->assertSame(0, BudgetActual::query()->whereIn('budget_line_id', $newVersion->lines()->pluck('id'))->count());
            $this->assertSame(Budget::STATUS_APPROVED, $original->status);
        });
    }

    public function test_new_version_rejects_a_draft_budget(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $budgetId = null;
        $tenant->run(function () use (&$budgetId) {
            $budgetId = $this->makeBudget()->id;
        });

        $this->post("/performance/budgets/{$budgetId}/new-version")->assertSessionHasErrors(['status']);
    }

    public function test_budget_index_filters(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $this->makeBudget(['name' => 'Draft Budget', 'fiscal_year' => 2027]);
            $this->makeBudget(['name' => 'Submitted Budget', 'status' => Budget::STATUS_SUBMITTED]);
        });

        $this->get('/performance/budgets?status='.Budget::STATUS_SUBMITTED)->assertOk()->assertInertia(fn ($page) => $page->has('budgets.data', 1));
        $this->get('/performance/budgets?fiscal_year=2027')->assertOk()->assertInertia(fn ($page) => $page->has('budgets.data', 1));
        $this->get('/performance/budgets?subject_type='.Budget::SUBJECT_COMPANY)->assertOk()->assertInertia(fn ($page) => $page->has('budgets.data', 2));
        $this->get('/performance/budgets?sort=fiscal_year&direction=desc')->assertOk();
    }

    public function test_admin_can_record_a_manual_budget_actual(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $lineId = null;
        $tenant->run(function () use (&$lineId) {
            $budget = $this->makeBudget();
            $lineId = $this->makeBudgetLine($budget, $this->makePeriod())->id;
        });

        $this->post("/performance/budget-lines/{$lineId}/actual", ['actual_value' => 950])->assertRedirect();

        $tenant->run(function () use ($lineId) {
            $this->assertEqualsWithDelta(950.0, (float) BudgetActual::query()->where('budget_line_id', $lineId)->value('actual_value'), 0.001);
        });

        // Re-posting upserts in place rather than creating a second row.
        $this->post("/performance/budget-lines/{$lineId}/actual", ['actual_value' => 999])->assertRedirect();
        $tenant->run(function () use ($lineId) {
            $this->assertSame(1, BudgetActual::query()->where('budget_line_id', $lineId)->count());
            $this->assertEqualsWithDelta(999.0, (float) BudgetActual::query()->where('budget_line_id', $lineId)->value('actual_value'), 0.001);
        });
    }

    public function test_admin_can_crud_a_budget_category_account_mapping(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$accountId, $companyId] = [null, null];
        $tenant->run(function () use (&$accountId, &$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $accountId = $this->makeAccount($company)->id;
        });

        $this->get('/performance/budget-category-accounts')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/BudgetCategoryAccounts/Index'));

        $this->post('/performance/budget-category-accounts', [
            'category' => 'Marketing', 'account_id' => $accountId, 'company_id' => $companyId,
        ])->assertRedirect(route('performance.budgetCategoryAccounts.index'));

        $mappingId = null;
        $tenant->run(function () use (&$mappingId) {
            $mappingId = BudgetCategoryAccount::query()->where('category', 'Marketing')->value('id');
        });

        $this->put("/performance/budget-category-accounts/{$mappingId}", [
            'category' => 'Marketing & Ads', 'account_id' => $accountId, 'company_id' => $companyId,
        ])->assertRedirect(route('performance.budgetCategoryAccounts.index'));

        $tenant->run(function () use ($mappingId) {
            $this->assertSame('Marketing & Ads', BudgetCategoryAccount::query()->find($mappingId)->category);
        });

        $this->delete("/performance/budget-category-accounts/{$mappingId}")->assertRedirect(route('performance.budgetCategoryAccounts.index'));
        $tenant->run(function () use ($mappingId) {
            $this->assertNull(BudgetCategoryAccount::query()->find($mappingId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids, $accountId) {
            $ids[] = $this->makeBudgetCategoryAccount('Bulk A', Account::find($accountId))->id;
            $ids[] = $this->makeBudgetCategoryAccount('Bulk B', Account::find($accountId))->id;
        });
        $this->delete('/performance/budget-category-accounts/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, BudgetCategoryAccount::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_mapping_store_rejects_duplicate_and_invalid_refs(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $accountId = null;
        $tenant->run(function () use (&$accountId) {
            $company = $this->makeCompany();
            $accountId = $this->makeAccount($company)->id;
            $this->makeBudgetCategoryAccount('Marketing', Account::find($accountId));
        });

        $this->post('/performance/budget-category-accounts', ['category' => 'Marketing', 'account_id' => $accountId])
            ->assertSessionHasErrors(['account_id']);

        $this->post('/performance/budget-category-accounts', ['category' => 'Bad', 'account_id' => 999999])
            ->assertSessionHasErrors(['account_id']);
    }

    public function test_mapping_index_filters_by_category(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $account = $this->makeAccount($company);
            $this->makeBudgetCategoryAccount('Findable Category', $account);
        });

        $this->get('/performance/budget-category-accounts?category=Findable')->assertOk()
            ->assertInertia(fn ($page) => $page->has('mappings.data', 1));
        $this->get('/performance/budget-category-accounts?sort=category&direction=asc')->assertOk();
    }

    public function test_variance_prefers_gl_actual_over_manual_when_a_mapping_resolves(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$budgetId, $lineId] = [null, null];
        $tenant->run(function () use (&$budgetId, &$lineId) {
            $company = $this->makeCompany();
            $account = $this->makeAccount($company, ['account_code' => '6100', 'account_name' => 'Marketing Expense']);
            $this->makeBudgetCategoryAccount('Marketing', $account);

            $period = $this->makePeriod('2026', ['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
            $fiscalPeriod = $this->makeFiscalPeriod($company, $period);
            $this->makePostedJournalLine($account, $fiscalPeriod, 1200000);

            $budget = $this->makeBudget();
            $budgetId = $budget->id;
            $line = $this->makeBudgetLine($budget, $period, ['category' => 'Marketing', 'amount_planned' => 1000000]);
            $lineId = $line->id;
            // Even though a manual actual also exists, the GL-sourced figure must win.
            $this->makeBudgetActual($line, 1);
        });

        $response = $this->get("/performance/budgets/{$budgetId}/edit")->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('budget.lines.0.variance.actual_source', 'gl')
            ->where('budget.lines.0.variance.actual_value', 1200000.0));
    }

    public function test_variance_falls_back_to_manual_actual_when_no_mapping_exists(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $budgetId = null;
        $tenant->run(function () use (&$budgetId) {
            $budget = $this->makeBudget();
            $budgetId = $budget->id;
            $line = $this->makeBudgetLine($budget, $this->makePeriod(), ['category' => 'Unmapped Category', 'amount_planned' => 1000]);
            $this->makeBudgetActual($line, 900);
        });

        $this->get("/performance/budgets/{$budgetId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('budget.lines.0.variance.actual_source', 'manual')
                ->where('budget.lines.0.variance.actual_value', 900.0));
    }

    public function test_variance_is_null_when_neither_gl_nor_manual_actual_exists(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $budgetId = null;
        $tenant->run(function () use (&$budgetId) {
            $budget = $this->makeBudget();
            $budgetId = $budget->id;
            $this->makeBudgetLine($budget, $this->makePeriod());
        });

        $this->get("/performance/budgets/{$budgetId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->where('budget.lines.0.variance', null)
                ->where('budget.lines.0.manual_actual_value', null));
    }
}
