<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AllocationRule;
use App\Modules\Accounting\Services\AllocationRuleService;
use App\Modules\Accounting\Services\AllocationRunService;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3I Cost Accounting — allocation rule CRUD; AllocationRunTest covers actually running one. */
class AllocationRuleTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_crud_a_rule(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $sourceAccountId, $costCenterAId, $costCenterBId] = [null, null, null, null];
        $tenant->run(function () use (&$companyId, &$sourceAccountId, &$costCenterAId, &$costCenterBId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $sourceAccountId = $this->makeAccount($company)->id;
            $costCenterAId = $this->makeCostCenter($company)->id;
            $costCenterBId = $this->makeCostCenter($company)->id;
        });

        $this->get("/accounting/allocation-rules?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/AllocationRules/Index'));
        $this->get("/accounting/allocation-rules/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/AllocationRules/Create'));
        // No company_id query param — formOptions()'s early-return branch.
        $this->get('/accounting/allocation-rules/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('accounts', [])->where('costCenters', []));

        $this->post('/accounting/allocation-rules', [
            'company_id' => $companyId, 'name' => 'Rent Split', 'source_account_id' => $sourceAccountId,
            'targets' => [
                ['cost_center_id' => $costCenterAId, 'percentage' => 60],
                ['cost_center_id' => $costCenterBId, 'percentage' => 40],
            ],
        ])->assertRedirect();

        $ruleId = null;
        $tenant->run(function () use (&$ruleId, $companyId) {
            $ruleId = AllocationRule::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/allocation-rules/{$ruleId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/AllocationRules/Edit')->has('rule.targets', 2));

        $this->put("/accounting/allocation-rules/{$ruleId}", [
            'name' => 'Rent Split (renamed)', 'source_account_id' => $sourceAccountId,
            'targets' => [
                ['cost_center_id' => $costCenterAId, 'percentage' => 50],
                ['cost_center_id' => $costCenterBId, 'percentage' => 50],
            ],
        ])->assertRedirect(route('accounting.allocation-rules.edit', $ruleId));

        $tenant->run(function () use ($ruleId) {
            $rule = AllocationRule::query()->find($ruleId);
            $this->assertSame('Rent Split (renamed)', $rule->name);
            $this->assertCount(2, $rule->targets);
        });

        $this->post("/accounting/allocation-rules/{$ruleId}/set-active", ['is_active' => false])->assertRedirect();
        $tenant->run(function () use ($ruleId) {
            $this->assertFalse(AllocationRule::query()->find($ruleId)->is_active);
        });

        $this->delete("/accounting/allocation-rules/{$ruleId}")->assertRedirect(route('accounting.allocation-rules.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($ruleId) {
            $this->assertNull(AllocationRule::query()->find($ruleId));
        });
    }

    public function test_store_rejects_invalid_references_and_bad_target_shapes(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $sourceAccountId, $costCenterId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$sourceAccountId, &$costCenterId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $sourceAccountId = $this->makeAccount($company)->id;
            $costCenterId = $this->makeCostCenter($company)->id;
        });

        $this->post('/accounting/allocation-rules', [
            'company_id' => 999999, 'name' => 'X', 'source_account_id' => 999999,
            'source_cost_center_id' => 999999,
            'targets' => [['cost_center_id' => 999999, 'percentage' => 100]],
        ])->assertSessionHasErrors(['company_id', 'source_account_id', 'source_cost_center_id', 'targets.0.cost_center_id']);

        // Targets don't sum to 100 — service-layer guard (FormRequest only checks each cell's own range).
        $this->post('/accounting/allocation-rules', [
            'company_id' => $companyId, 'name' => 'X', 'source_account_id' => $sourceAccountId,
            'targets' => [['cost_center_id' => $costCenterId, 'percentage' => 60]],
        ])->assertSessionHasErrors(['targets']);
    }

    public function test_store_rejects_duplicate_targets_and_a_target_equal_to_the_source(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $sourceAccountId, $costCenterAId, $costCenterBId] = [null, null, null, null];
        $tenant->run(function () use (&$companyId, &$sourceAccountId, &$costCenterAId, &$costCenterBId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $sourceAccountId = $this->makeAccount($company)->id;
            $costCenterAId = $this->makeCostCenter($company)->id;
            $costCenterBId = $this->makeCostCenter($company)->id;
        });

        $this->post('/accounting/allocation-rules', [
            'company_id' => $companyId, 'name' => 'X', 'source_account_id' => $sourceAccountId,
            'targets' => [
                ['cost_center_id' => $costCenterAId, 'percentage' => 50],
                ['cost_center_id' => $costCenterAId, 'percentage' => 50],
            ],
        ])->assertSessionHasErrors(['targets']);

        $this->post('/accounting/allocation-rules', [
            'company_id' => $companyId, 'name' => 'X', 'source_account_id' => $sourceAccountId,
            'source_cost_center_id' => $costCenterAId,
            'targets' => [
                ['cost_center_id' => $costCenterAId, 'percentage' => 60],
                ['cost_center_id' => $costCenterBId, 'percentage' => 40],
            ],
        ])->assertSessionHasErrors(['targets']);
    }

    public function test_update_rejects_invalid_references(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$ruleId, $sourceAccountId] = [null, null];
        $tenant->run(function () use (&$ruleId, &$sourceAccountId) {
            $company = $this->makeCompany();
            $sourceAccountId = $this->makeAccount($company)->id;
            $costCenter = $this->makeCostCenter($company);
            $ruleId = app(AllocationRuleService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'source_account_id' => $sourceAccountId],
                [['cost_center_id' => $costCenter->id, 'percentage' => 100]],
                $this->adminUserId(),
            )->id;
        });

        $this->put("/accounting/allocation-rules/{$ruleId}", [
            'name' => 'X', 'source_account_id' => 999999, 'source_cost_center_id' => 999999,
            'targets' => [['cost_center_id' => 999999, 'percentage' => 100]],
        ])->assertSessionHasErrors(['source_account_id', 'source_cost_center_id', 'targets.0.cost_center_id']);
    }

    public function test_update_rejects_an_empty_target_list(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$ruleId, $sourceAccountId] = [null, null];
        $tenant->run(function () use (&$ruleId, &$sourceAccountId) {
            $company = $this->makeCompany();
            $sourceAccountId = $this->makeAccount($company)->id;
            $costCenter = $this->makeCostCenter($company);
            $ruleId = app(AllocationRuleService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'source_account_id' => $sourceAccountId],
                [['cost_center_id' => $costCenter->id, 'percentage' => 100]],
                $this->adminUserId(),
            )->id;
        });

        // FormRequest itself requires 'targets' min:1 — reaching the service's own "empty
        // targets" guard directly needs a bypass, since HTTP can never submit a truly empty array.
        $tenant->run(function () use ($ruleId) {
            $rule = AllocationRule::query()->find($ruleId);

            $this->expectException(ValidationException::class);
            app(AllocationRuleService::class)->update($rule, ['name' => 'X'], [], $this->adminUserId());
        });
    }

    public function test_delete_is_blocked_once_the_rule_has_been_run(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $ruleId = null;
        $tenant->run(function () use (&$ruleId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $sourceAccount = $this->makeAccount($company);
            $costCenter = $this->makeCostCenter($company);
            $ruleService = app(AllocationRuleService::class);
            $rule = $ruleService->create(
                ['company_id' => $company->id, 'name' => 'X', 'source_account_id' => $sourceAccount->id],
                [['cost_center_id' => $costCenter->id, 'percentage' => 100]],
                $this->adminUserId(),
            );
            $ruleId = $rule->id;

            // Post something to the source account so there's a pool to allocate.
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            $journal = $this->makeJournal($company, $period, ['debit_account' => $sourceAccount, 'credit_account' => $offsetAccount, 'amount' => 100000]);
            app(JournalService::class)->post($journal, $this->adminUserId());

            app(AllocationRunService::class)->run($rule, $period, $this->adminUserId());
        });

        $this->delete("/accounting/allocation-rules/{$ruleId}")->assertSessionHasErrors(['rule']);
    }
}
