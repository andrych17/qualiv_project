<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AllocationRun;
use App\Modules\Accounting\Services\AllocationRuleService;
use App\Modules\Accounting\Services\AllocationRunService;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3I — running an allocation rule for a period: reads the source pool's posted balance, then posts one same-account journal redistributing it by cost center. */
class AllocationRunTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_preview_and_run_an_allocation(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$ruleId, $periodId, $sourceAccountId, $costCenterAId, $costCenterBId] = [null, null, null, null, null];
        $tenant->run(function () use (&$ruleId, &$periodId, &$sourceAccountId, &$costCenterAId, &$costCenterBId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;
            $sourceAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);
            $sourceAccountId = $sourceAccount->id;
            $costCenterAId = $this->makeCostCenter($company)->id;
            $costCenterBId = $this->makeCostCenter($company)->id;

            $rule = app(AllocationRuleService::class)->create(
                ['company_id' => $company->id, 'name' => 'Rent Split', 'source_account_id' => $sourceAccountId],
                [['cost_center_id' => $costCenterAId, 'percentage' => 60], ['cost_center_id' => $costCenterBId, 'percentage' => 40]],
                $this->adminUserId(),
            );
            $ruleId = $rule->id;

            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            $journal = $this->makeJournal($company, $period, ['debit_account' => $sourceAccount, 'credit_account' => $offsetAccount, 'amount' => 1000000]);
            app(JournalService::class)->post($journal, $this->adminUserId());
        });

        $this->get("/accounting/allocation-rules/{$ruleId}/run?fiscal_period_id={$periodId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/AllocationRules/Run')
                ->where('alreadyRun', false)
                ->where('preview.sourceAmount', 1000000)
                ->has('preview.lines', 2));

        $this->post("/accounting/allocation-rules/{$ruleId}/run", ['fiscal_period_id' => $periodId])->assertRedirect();

        $tenant->run(function () use ($ruleId, $periodId, $sourceAccountId, $costCenterAId, $costCenterBId) {
            $run = AllocationRun::query()->where('allocation_rule_id', $ruleId)->where('fiscal_period_id', $periodId)->first();
            $this->assertNotNull($run);
            $this->assertEqualsWithDelta(1000000.0, (float) $run->source_amount, 0.01);
            $this->assertNotNull($run->journal_id);

            $this->assertTrue($run->journal->lines()->where('account_id', $sourceAccountId)->where('cost_center_id', $costCenterAId)->where('debit', 600000)->exists());
            $this->assertTrue($run->journal->lines()->where('account_id', $sourceAccountId)->where('cost_center_id', $costCenterBId)->where('debit', 400000)->exists());
            $this->assertTrue($run->journal->lines()->where('account_id', $sourceAccountId)->whereNull('cost_center_id')->where('credit', 1000000)->exists());
        });

        // Show page now reflects "already run" for this period — no fresh preview.
        $this->get("/accounting/allocation-rules/{$ruleId}/run?fiscal_period_id={$periodId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('alreadyRun', true)->where('preview', null)->has('runs', 1));
    }

    public function test_run_rejects_a_period_already_run_and_a_period_with_nothing_posted(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$ruleId, $periodId, $emptyRuleId] = [null, null, null];
        $tenant->run(function () use (&$ruleId, &$periodId, &$emptyRuleId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;
            $sourceAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);
            $costCenter = $this->makeCostCenter($company);

            $ruleService = app(AllocationRuleService::class);
            $rule = $ruleService->create(
                ['company_id' => $company->id, 'name' => 'Rent Split', 'source_account_id' => $sourceAccount->id],
                [['cost_center_id' => $costCenter->id, 'percentage' => 100]],
                $this->adminUserId(),
            );
            $ruleId = $rule->id;

            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            $journal = $this->makeJournal($company, $period, ['debit_account' => $sourceAccount, 'credit_account' => $offsetAccount, 'amount' => 500000]);
            app(JournalService::class)->post($journal, $this->adminUserId());
            app(AllocationRunService::class)->run($rule, $period, $this->adminUserId());

            // A second rule with nothing ever posted to its source account/cost center.
            $emptySourceAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $emptyRule = $ruleService->create(
                ['company_id' => $company->id, 'name' => 'Empty Pool', 'source_account_id' => $emptySourceAccount->id],
                [['cost_center_id' => $costCenter->id, 'percentage' => 100]],
                $this->adminUserId(),
            );
            $emptyRuleId = $emptyRule->id;
        });

        $this->post("/accounting/allocation-rules/{$ruleId}/run", ['fiscal_period_id' => $periodId])->assertSessionHasErrors(['fiscal_period_id']);
        $this->post("/accounting/allocation-rules/{$emptyRuleId}/run", ['fiscal_period_id' => $periodId])->assertSessionHasErrors(['fiscal_period_id']);
    }

    /** No fiscal periods exist at all for the rule's company — $selectedPeriodId (and so $selectedPeriod) stays null, the ternaries' false branch. */
    public function test_show_page_for_a_rule_whose_company_has_no_fiscal_periods_at_all(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $ruleId = null;
        $tenant->run(function () use (&$ruleId) {
            $company = $this->makeCompany();
            $sourceAccount = $this->makeAccount($company);
            $costCenter = $this->makeCostCenter($company);
            $ruleId = app(AllocationRuleService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'source_account_id' => $sourceAccount->id],
                [['cost_center_id' => $costCenter->id, 'percentage' => 100]],
                $this->adminUserId(),
            )->id;
        });

        $this->get("/accounting/allocation-rules/{$ruleId}/run")->assertOk()
            ->assertInertia(fn ($page) => $page->where('selectedPeriodId', null)->where('alreadyRun', false)->where('preview', null)->has('periods', 0));
    }

    public function test_store_rejects_a_period_belonging_to_a_different_company(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$ruleId, $otherCompanyPeriodId] = [null, null];
        $tenant->run(function () use (&$ruleId, &$otherCompanyPeriodId) {
            $company = $this->makeCompany(['legal_name' => 'A']);
            $sourceAccount = $this->makeAccount($company);
            $costCenter = $this->makeCostCenter($company);
            $ruleId = app(AllocationRuleService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'source_account_id' => $sourceAccount->id],
                [['cost_center_id' => $costCenter->id, 'percentage' => 100]],
                $this->adminUserId(),
            )->id;

            $otherCompany = $this->makeCompany(['legal_name' => 'B']);
            $otherCompanyPeriodId = $this->firstPeriod($this->makeFiscalYear($otherCompany))->id;
        });

        $this->post("/accounting/allocation-rules/{$ruleId}/run", ['fiscal_period_id' => $otherCompanyPeriodId])
            ->assertSessionHasErrors(['fiscal_period_id']);
    }

    /** The source account's normal_balance flips the pool's sign — a credit-normal (revenue) source account is the mirror case of the usual debit-normal expense pool. */
    public function test_run_handles_a_credit_normal_source_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $sourceAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE, 'normal_balance' => Account::BALANCE_CREDIT]);
            $costCenter = $this->makeCostCenter($company);
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);

            $rule = app(AllocationRuleService::class)->create(
                ['company_id' => $company->id, 'name' => 'Revenue Split', 'source_account_id' => $sourceAccount->id],
                [['cost_center_id' => $costCenter->id, 'percentage' => 100]],
                $this->adminUserId(),
            );

            $journal = $this->makeJournal($company, $period, ['debit_account' => $offsetAccount, 'credit_account' => $sourceAccount, 'amount' => 750000]);
            app(JournalService::class)->post($journal, $this->adminUserId());

            $run = app(AllocationRunService::class)->run($rule, $period, $this->adminUserId());
            $this->assertEqualsWithDelta(750000.0, (float) $run->source_amount, 0.01);
        });
    }
}
