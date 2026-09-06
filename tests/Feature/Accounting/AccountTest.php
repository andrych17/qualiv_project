<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use App\Modules\Accounting\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3B Chart of Accounts — depth-indented tree CRUD, control-account flagging, the starter Indonesian-standard COA. */
class AccountTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_crud_an_account_with_hierarchy(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/accounts?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Accounts/Index'));
        $this->get("/accounting/accounts/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Accounts/Create'));

        $this->post('/accounting/accounts', [
            'company_id' => $companyId, 'account_code' => '50000', 'account_name' => 'Test Parent',
            'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT,
        ])->assertRedirect(route('accounting.accounts.index', ['company_id' => $companyId]));

        $parentId = null;
        $tenant->run(function () use (&$parentId, $companyId) {
            $parentId = Account::query()->where('company_id', $companyId)->where('account_code', '50000')->value('id');
        });

        $this->post('/accounting/accounts', [
            'company_id' => $companyId, 'account_code' => '50001', 'account_name' => 'Test Child',
            'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT,
            'parent_account_id' => $parentId,
        ])->assertRedirect();

        $childId = null;
        $tenant->run(function () use (&$childId) {
            $childId = Account::query()->where('account_code', '50001')->value('id');
        });

        $this->get("/accounting/accounts/{$childId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->where('account.account_name', 'Test Child'));

        // Editing the PARENT (which has a child) excludes its own subtree from the parent
        // options list — exercises AccountController::subtreeIds()'s descendant walk.
        $this->get("/accounting/accounts/{$parentId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->where('account.account_name', 'Test Parent'));

        // No company_id query param — AccountController::parentOptions()'s early-return branch.
        $this->get('/accounting/accounts/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Accounts/Create')->where('parents', []));

        $this->put("/accounting/accounts/{$childId}", [
            'account_code' => '50001', 'account_name' => 'Test Child (renamed)',
            'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT,
            'is_control_account' => false, 'is_active' => true,
        ])->assertRedirect(route('accounting.accounts.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($childId) {
            $this->assertSame('Test Child (renamed)', Account::query()->find($childId)->account_name);
        });

        $this->delete("/accounting/accounts/{$childId}")->assertRedirect(route('accounting.accounts.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($childId) {
            $this->assertNull(Account::query()->find($childId));
        });
    }

    public function test_store_rejects_invalid_company_parent_and_duplicate_code(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeAccount($company, ['account_code' => 'DUP']);
        });

        $this->post('/accounting/accounts', [
            'company_id' => 999999, 'account_code' => 'X', 'account_name' => 'X',
            'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT,
            'parent_account_id' => 999999,
        ])->assertSessionHasErrors(['company_id', 'parent_account_id']);

        $this->post('/accounting/accounts', [
            'company_id' => $companyId, 'account_code' => 'DUP', 'account_name' => 'Duplicate',
            'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT,
        ])->assertSessionHasErrors(['account_code']);
    }

    /**
     * UpdateAccountRequest has no self-parent check of its own (unlike UpdateCostCenterRequest) —
     * this is caught by AccountService::assertParentSameCompany()'s guard instead, which throws a
     * plain ValidationException. Laravel's default exception handler converts an uncaught
     * ValidationException from a web request into the same redirect-back-with-errors response a
     * FormRequest failure would produce, so this is still a normal, non-fatal rejection.
     */
    public function test_update_rejects_self_as_parent_via_the_service_layer_guard(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $accountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$accountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $accountId = $this->makeAccount($company, ['account_code' => '77000'])->id;
        });

        $this->put("/accounting/accounts/{$accountId}", [
            'account_code' => '77000', 'account_name' => 'Self Parent',
            'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT,
            'parent_account_id' => $accountId,
        ])->assertSessionHasErrors(['parent_account_id']);

        $tenant->run(function () use ($accountId) {
            $this->assertNull(Account::query()->find($accountId)->parent_account_id);
        });
    }

    public function test_update_rejects_an_invalid_parent_and_a_duplicate_code(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$accountId, $otherCode] = [null, null];
        $tenant->run(function () use (&$accountId, &$otherCode) {
            $company = $this->makeCompany();
            $accountId = $this->makeAccount($company, ['account_code' => '78000'])->id;
            $otherCode = $this->makeAccount($company, ['account_code' => '78001'])->account_code;
        });

        $this->put("/accounting/accounts/{$accountId}", [
            'account_code' => '78000', 'account_name' => 'Bad Parent',
            'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT,
            'parent_account_id' => 999999,
        ])->assertSessionHasErrors(['parent_account_id']);

        $this->put("/accounting/accounts/{$accountId}", [
            'account_code' => $otherCode, 'account_name' => 'Dupe Code',
            'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT,
        ])->assertSessionHasErrors(['account_code']);
    }

    /** UpdateAccountRequest only checks the parent exists — a parent from a different company is a service-layer-only guard, like CostCenterService's equivalent. */
    public function test_service_layer_rejects_a_parent_from_a_different_company(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $companyA = $this->makeCompany(['legal_name' => 'Company A']);
            $companyB = $this->makeCompany(['legal_name' => 'Company B']);
            $accountA = $this->makeAccount($companyA);
            $parentB = $this->makeAccount($companyB);

            $this->expectException(ValidationException::class);
            app(AccountService::class)->update($accountA, [
                'account_code' => $accountA->account_code, 'account_name' => $accountA->account_name,
                'account_type' => $accountA->account_type, 'normal_balance' => $accountA->normal_balance,
                'parent_account_id' => $parentB->id,
            ]);
        });
    }

    public function test_delete_is_blocked_when_account_has_children_or_journal_activity(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$parentId, $postedId] = [null, null];
        $tenant->run(function () use (&$parentId, &$postedId) {
            $company = $this->makeCompany();
            $parent = $this->makeAccount($company);
            $parentId = $parent->id;
            $this->makeAccount($company, ['parent_account_id' => $parent->id]);

            $posted = $this->makeAccount($company);
            $postedId = $posted->id;
            $journal = GlJournal::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $company->id,
                'fiscal_period_id' => $this->firstPeriod($this->makeFiscalYear($company))->id,
                'journal_date' => now(), 'currency_code' => 'IDR', 'status' => GlJournal::STATUS_DRAFT,
            ]);
            GlJournalLine::query()->create(['journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $posted->id, 'debit' => 100, 'credit' => 0]);
        });

        $this->delete("/accounting/accounts/{$parentId}")->assertSessionHasErrors(['account']);
        $this->delete("/accounting/accounts/{$postedId}")->assertSessionHasErrors(['account']);
    }

    public function test_account_index_shows_indented_tree(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $parent = $this->makeAccount($company, ['account_code' => '10000', 'account_name' => 'Parent']);
            $this->makeAccount($company, ['account_code' => '10001', 'account_name' => 'Child', 'parent_account_id' => $parent->id]);
        });

        $this->get("/accounting/accounts?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('accounts', 2)
                ->where('accounts.0.depth', 0)
                ->where('accounts.1.depth', 1));
    }

    public function test_admin_can_seed_the_starter_coa_once(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->post("/accounting/companies/{$companyId}/seed-starter-coa")->assertRedirect(route('accounting.accounts.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($companyId) {
            $company = Company::query()->find($companyId);
            $this->assertSame('ID_STANDARD', $company->coa_template_code);
            $this->assertNotNull($company->ar_control_account_id);
            $this->assertNotNull($company->ap_control_account_id);
            $this->assertNotNull($company->inventory_control_account_id);
            $this->assertNotNull($company->payroll_net_pay_payable_account_id);
            $this->assertGreaterThan(20, Account::query()->where('company_id', $companyId)->count());
            $this->assertTrue(Account::query()->find($company->ar_control_account_id)->is_control_account);
        });

        // Re-seeding an already-set-up company is rejected — never silently duplicates the COA.
        $this->post("/accounting/companies/{$companyId}/seed-starter-coa")->assertSessionHasErrors(['account']);
    }
}
