<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Events\PayrollRunPaid;
use App\Modules\Accounting\Listeners\PostPayrollRunToGl;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\PayrollComponentGlMapping;
use App\Modules\Accounting\Models\PayrollGlPosting;
use App\Modules\Accounting\Models\PayrollPostingFailure;
use App\Modules\Accounting\Services\PayrollComponentGlMappingService;
use App\Modules\Accounting\Services\PayrollGlPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3S Payroll GL Posting — financial-side-only interface engine; consumes a PayrollRunPaid event Payroll's own engine will dispatch once it ships. */
class PayrollGlPostingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_crud_a_mapping(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $glAccountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$glAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $glAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
        });

        $this->get("/accounting/payroll-component-gl-mappings?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/PayrollComponentGlMappings/Index'));
        $this->get("/accounting/payroll-component-gl-mappings/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/PayrollComponentGlMappings/Create'));
        // No company_id query param — accountOptions()'s early-return branch.
        $this->get('/accounting/payroll-component-gl-mappings/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('accounts', []));

        $this->post('/accounting/payroll-component-gl-mappings', [
            'company_id' => $companyId, 'component_code' => 'BASIC_SALARY', 'component_label' => 'Basic Salary',
            'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $glAccountId,
        ])->assertRedirect(route('accounting.payroll-component-gl-mappings.index', ['company_id' => $companyId]));

        $mappingId = null;
        $tenant->run(function () use (&$mappingId, $companyId) {
            $mappingId = PayrollComponentGlMapping::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/payroll-component-gl-mappings/{$mappingId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/PayrollComponentGlMappings/Edit'));

        $this->put("/accounting/payroll-component-gl-mappings/{$mappingId}", [
            'component_label' => 'Basic Salary (renamed)', 'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $glAccountId,
        ])->assertRedirect(route('accounting.payroll-component-gl-mappings.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($mappingId) {
            $this->assertSame('Basic Salary (renamed)', PayrollComponentGlMapping::query()->find($mappingId)->component_label);
        });

        $this->delete("/accounting/payroll-component-gl-mappings/{$mappingId}")->assertRedirect(route('accounting.payroll-component-gl-mappings.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($mappingId) {
            $this->assertNull(PayrollComponentGlMapping::query()->find($mappingId));
        });
    }

    public function test_store_rejects_invalid_company_account_duplicate_code_and_a_missing_payable_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $glAccountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$glAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $glAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
        });

        $this->post('/accounting/payroll-component-gl-mappings', [
            'company_id' => 999999, 'component_code' => 'X', 'component_label' => 'X',
            'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => 999999,
        ])->assertSessionHasErrors(['company_id', 'gl_account_id']);

        $this->post('/accounting/payroll-component-gl-mappings', [
            'company_id' => $companyId, 'component_code' => 'DUP', 'component_label' => 'X',
            'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $glAccountId,
        ])->assertRedirect();
        $this->post('/accounting/payroll-component-gl-mappings', [
            'company_id' => $companyId, 'component_code' => 'DUP', 'component_label' => 'X2',
            'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $glAccountId,
        ])->assertSessionHasErrors(['component_code']);

        // employer_cost needs a payable_account_id too — service-level guard, not the FormRequest's own rule.
        $this->post('/accounting/payroll-component-gl-mappings', [
            'company_id' => $companyId, 'component_code' => 'BPJS_ER', 'component_label' => 'Employer BPJS',
            'component_type' => PayrollComponentGlMapping::TYPE_EMPLOYER_COST, 'gl_account_id' => $glAccountId,
        ])->assertSessionHasErrors(['payable_account_id']);
    }

    public function test_update_rejects_switching_to_employer_cost_without_a_payable_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$mappingId, $glAccountId] = [null, null];
        $tenant->run(function () use (&$mappingId, &$glAccountId) {
            $company = $this->makeCompany();
            $glAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $mappingId = app(PayrollComponentGlMappingService::class)->create([
                'company_id' => $company->id, 'component_code' => 'X', 'component_label' => 'X',
                'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $glAccountId,
            ], $this->adminUserId())->id;
        });

        $this->put("/accounting/payroll-component-gl-mappings/{$mappingId}", [
            'component_label' => 'X', 'component_type' => PayrollComponentGlMapping::TYPE_EMPLOYER_COST, 'gl_account_id' => $glAccountId,
        ])->assertSessionHasErrors(['payable_account_id']);
    }

    public function test_update_rejects_invalid_accounts(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $mappingId = null;
        $tenant->run(function () use (&$mappingId) {
            $company = $this->makeCompany();
            $glAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $mappingId = app(PayrollComponentGlMappingService::class)->create([
                'company_id' => $company->id, 'component_code' => 'X', 'component_label' => 'X',
                'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $glAccountId,
            ], $this->adminUserId())->id;
        });

        $this->put("/accounting/payroll-component-gl-mappings/{$mappingId}", [
            'component_label' => 'X', 'component_type' => PayrollComponentGlMapping::TYPE_EMPLOYER_COST,
            'gl_account_id' => 999999, 'payable_account_id' => 999999,
        ])->assertSessionHasErrors(['gl_account_id', 'payable_account_id']);
    }

    public function test_run_paid_posts_earnings_deductions_and_employer_cost_with_correct_net_pay(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $salaryAccountId, $taxPayableAccountId, $bpjsExpenseAccountId, $bpjsPayableAccountId, $netPayAccountId] = [null, null, null, null, null, null];
        $tenant->run(function () use (&$companyId, &$salaryAccountId, &$taxPayableAccountId, &$bpjsExpenseAccountId, &$bpjsPayableAccountId, &$netPayAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeFiscalYear($company);
            $salaryAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $taxPayableAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY])->id;
            $bpjsExpenseAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $bpjsPayableAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY])->id;
            $netPayAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY])->id;
            $company->update(['payroll_net_pay_payable_account_id' => $netPayAccountId]);

            $mappingService = app(PayrollComponentGlMappingService::class);
            $mappingService->create(['company_id' => $companyId, 'component_code' => 'BASIC', 'component_label' => 'Basic Salary', 'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $salaryAccountId], $this->adminUserId());
            $mappingService->create(['company_id' => $companyId, 'component_code' => 'PPH21', 'component_label' => 'PPh 21', 'component_type' => PayrollComponentGlMapping::TYPE_DEDUCTION, 'gl_account_id' => $taxPayableAccountId], $this->adminUserId());
            $mappingService->create(['company_id' => $companyId, 'component_code' => 'BPJS_ER', 'component_label' => 'Employer BPJS', 'component_type' => PayrollComponentGlMapping::TYPE_EMPLOYER_COST, 'gl_account_id' => $bpjsExpenseAccountId, 'payable_account_id' => $bpjsPayableAccountId], $this->adminUserId());
        });

        $tenant->run(function () use ($companyId, $salaryAccountId, $taxPayableAccountId, $bpjsExpenseAccountId, $bpjsPayableAccountId, $netPayAccountId) {
            $event = new PayrollRunPaid($companyId, '2026-01-25', [
                ['component_code' => 'BASIC', 'amount' => 10000000],
                ['component_code' => 'PPH21', 'amount' => 500000],
                ['component_code' => 'BPJS_ER', 'amount' => 400000],
            ], 'payroll.payroll_runs', 'RUN-1');

            app(PostPayrollRunToGl::class)->handle($event);

            $posting = PayrollGlPosting::query()->where('subject_id', 'RUN-1')->first();
            $this->assertNotNull($posting);
            $journal = $posting->journal;

            $this->assertTrue($journal->lines()->where('account_id', $salaryAccountId)->where('debit', 10000000)->exists());
            $this->assertTrue($journal->lines()->where('account_id', $taxPayableAccountId)->where('credit', 500000)->exists());
            $this->assertTrue($journal->lines()->where('account_id', $bpjsExpenseAccountId)->where('debit', 400000)->exists());
            $this->assertTrue($journal->lines()->where('account_id', $bpjsPayableAccountId)->where('credit', 400000)->exists());
            // Net pay = earnings (10,000,000) - deductions (500,000) = 9,500,000 — employer_cost never touches it.
            $this->assertTrue($journal->lines()->where('account_id', $netPayAccountId)->where('credit', 9500000)->exists());
            $this->assertEqualsWithDelta((float) $journal->lines()->sum('debit'), (float) $journal->lines()->sum('credit'), 0.01);

            // Idempotent: replaying the same event posts nothing new.
            app(PostPayrollRunToGl::class)->handle($event);
            $this->assertSame(1, PayrollGlPosting::query()->where('subject_id', 'RUN-1')->count());
        });
    }

    public function test_negative_net_pay_debits_the_net_pay_payable_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $salaryAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $advanceAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $netPayAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);
            $company->update(['payroll_net_pay_payable_account_id' => $netPayAccount->id]);

            $mappingService = app(PayrollComponentGlMappingService::class);
            $mappingService->create(['company_id' => $company->id, 'component_code' => 'BASIC', 'component_label' => 'Basic', 'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $salaryAccount->id], $this->adminUserId());
            $mappingService->create(['company_id' => $company->id, 'component_code' => 'ADVANCE', 'component_label' => 'Advance Recovery', 'component_type' => PayrollComponentGlMapping::TYPE_DEDUCTION, 'gl_account_id' => $advanceAccount->id], $this->adminUserId());

            // Deductions (2,000,000) exceed earnings (500,000) -> net pay = -1,500,000, the debit branch.
            $event = new PayrollRunPaid($company->id, '2026-01-25', [
                ['component_code' => 'BASIC', 'amount' => 500000],
                ['component_code' => 'ADVANCE', 'amount' => 2000000],
            ], 'payroll.payroll_runs', 'RUN-NEGATIVE');
            app(PostPayrollRunToGl::class)->handle($event);

            $posting = PayrollGlPosting::query()->where('subject_id', 'RUN-NEGATIVE')->first();
            $this->assertTrue($posting->journal->lines()->where('account_id', $netPayAccount->id)->where('debit', 1500000)->exists());
        });
    }

    public function test_duplicate_component_codes_in_one_event_are_aggregated(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $salaryAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $netPayAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);
            $company->update(['payroll_net_pay_payable_account_id' => $netPayAccount->id]);
            app(PayrollComponentGlMappingService::class)->create(['company_id' => $company->id, 'component_code' => 'BASIC', 'component_label' => 'Basic Salary', 'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $salaryAccount->id], $this->adminUserId());

            // Two employees' BASIC lines in one run-level event -> summed to one 15,000,000 GL line.
            $event = new PayrollRunPaid($company->id, '2026-01-25', [
                ['component_code' => 'BASIC', 'amount' => 10000000],
                ['component_code' => 'BASIC', 'amount' => 5000000],
            ], 'payroll.payroll_runs', 'RUN-AGG');
            app(PostPayrollRunToGl::class)->handle($event);

            $posting = PayrollGlPosting::query()->where('subject_id', 'RUN-AGG')->first();
            $this->assertTrue($posting->journal->lines()->where('account_id', $salaryAccount->id)->where('debit', 15000000)->exists());
        });
    }

    public function test_failures_are_queued_for_unmapped_component_incomplete_employer_cost_missing_control_account_and_no_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $salaryAccountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$salaryAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeFiscalYear($company);
            $salaryAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
        });

        // 1. No mapping at all for UNKNOWN.
        $tenant->run(function () use ($companyId) {
            app(PostPayrollRunToGl::class)->handle(new PayrollRunPaid($companyId, '2026-01-25', [['component_code' => 'UNKNOWN', 'amount' => 1000]], 'payroll.payroll_runs', 'RUN-NOMAP'));
        });

        // 2. Mapped, but no net_pay_payable_account_id configured yet.
        $tenant->run(function () use ($companyId, $salaryAccountId) {
            app(PayrollComponentGlMappingService::class)->create(['company_id' => $companyId, 'component_code' => 'BASIC', 'component_label' => 'Basic', 'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $salaryAccountId], $this->adminUserId());
            app(PostPayrollRunToGl::class)->handle(new PayrollRunPaid($companyId, '2026-01-25', [['component_code' => 'BASIC', 'amount' => 1000000]], 'payroll.payroll_runs', 'RUN-NOCONTROL'));
        });

        // 3. Control account now configured, but an employer_cost mapping missing its payable_account_id.
        $tenant->run(function () use ($companyId, $salaryAccountId) {
            $company = Company::find($companyId);
            $netPayAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);
            $company->update(['payroll_net_pay_payable_account_id' => $netPayAccount->id]);

            // Bypass the service's own guard to create an intentionally-incomplete mapping (simulating pre-existing bad data).
            PayrollComponentGlMapping::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'component_code' => 'BPJS_ER',
                'component_label' => 'Employer BPJS', 'component_type' => PayrollComponentGlMapping::TYPE_EMPLOYER_COST,
                'gl_account_id' => $salaryAccountId, 'payable_account_id' => null,
            ]);
            app(PostPayrollRunToGl::class)->handle(new PayrollRunPaid($companyId, '2026-01-25', [['component_code' => 'BPJS_ER', 'amount' => 100000]], 'payroll.payroll_runs', 'RUN-INCOMPLETE'));
        });

        // 4. Mapped and control account configured, but no fiscal period covers the run date.
        $tenant->run(function () use ($companyId) {
            app(PostPayrollRunToGl::class)->handle(new PayrollRunPaid($companyId, '2027-06-01', [['component_code' => 'BASIC', 'amount' => 1000000]], 'payroll.payroll_runs', 'RUN-NOPERIOD'));
        });

        $tenant->run(function () use ($companyId) {
            $this->assertSame(4, PayrollPostingFailure::query()->where('company_id', $companyId)->count());
        });

        $this->get("/accounting/payroll-posting-failures?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/PayrollPostingFailures/Index')->has('failures', 4));

        // Retry the "no control account" one after fixing it retroactively — control account is now set, so it succeeds.
        $failureId = null;
        $tenant->run(function () use (&$failureId) {
            $failureId = PayrollPostingFailure::query()->where('subject_id', 'RUN-NOCONTROL')->value('id');
        });
        $this->post("/accounting/payroll-posting-failures/{$failureId}/retry")->assertRedirect();
        $tenant->run(function () use ($failureId) {
            $this->assertSame(PayrollPostingFailure::STATUS_RESOLVED, PayrollPostingFailure::query()->find($failureId)->status);
        });

        // Retrying an already-resolved failure is rejected.
        $this->post("/accounting/payroll-posting-failures/{$failureId}/retry")->assertSessionHasErrors(['failure']);

        // Retrying a still-broken one (unmapped component) reports "still failing".
        $stillFailingId = null;
        $tenant->run(function () use (&$stillFailingId) {
            $stillFailingId = PayrollPostingFailure::query()->where('subject_id', 'RUN-NOMAP')->value('id');
        });
        $this->post("/accounting/payroll-posting-failures/{$stillFailingId}/retry")->assertSessionHasErrors(['failure']);
    }

    public function test_run_with_a_missing_company_or_all_zero_lines_is_silently_skipped(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            // Missing company entirely.
            app(PostPayrollRunToGl::class)->handle(new PayrollRunPaid(999999, '2026-01-25', [['component_code' => 'X', 'amount' => 100]], 'payroll.payroll_runs', 'RUN-NOCOMPANY'));
            $this->assertNull(PayrollPostingFailure::query()->where('subject_id', 'RUN-NOCOMPANY')->first());
            $this->assertNull(PayrollGlPosting::query()->where('subject_id', 'RUN-NOCOMPANY')->first());

            // Mapped component, but the amount rounds to zero -> nothing to post, no row of either kind.
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $salaryAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $netPayAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);
            $company->update(['payroll_net_pay_payable_account_id' => $netPayAccount->id]);
            app(PayrollComponentGlMappingService::class)->create(['company_id' => $company->id, 'component_code' => 'BASIC', 'component_label' => 'Basic', 'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $salaryAccount->id], $this->adminUserId());

            app(PostPayrollRunToGl::class)->handle(new PayrollRunPaid($company->id, '2026-01-25', [['component_code' => 'BASIC', 'amount' => 0]], 'payroll.payroll_runs', 'RUN-ZERO'));
            $this->assertNull(PayrollGlPosting::query()->where('subject_id', 'RUN-ZERO')->first());
            $this->assertNull(PayrollPostingFailure::query()->where('subject_id', 'RUN-ZERO')->first());
        });
    }

    public function test_retry_rebuilds_the_event_from_the_stored_payload(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $salaryAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $netPayAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            // No control account yet -> first attempt fails and queues.
            app(PayrollComponentGlMappingService::class)->create(['company_id' => $company->id, 'component_code' => 'BASIC', 'component_label' => 'Basic', 'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $salaryAccount->id], $this->adminUserId());
            app(PostPayrollRunToGl::class)->handle(new PayrollRunPaid($company->id, '2026-01-25', [['component_code' => 'BASIC', 'amount' => 2000000]], 'payroll.payroll_runs', 'RUN-RETRY'));

            $failure = PayrollPostingFailure::query()->where('subject_id', 'RUN-RETRY')->first();
            $this->assertNotNull($failure);

            $company->update(['payroll_net_pay_payable_account_id' => $netPayAccount->id]);

            app(PayrollGlPostingService::class)->retry($failure);

            $this->assertNotNull(PayrollGlPosting::query()->where('subject_id', 'RUN-RETRY')->first());
            $this->assertSame(PayrollPostingFailure::STATUS_RESOLVED, $failure->fresh()->status);

            $this->expectException(ValidationException::class);
            app(PayrollGlPostingService::class)->retry($failure->fresh());
        });
    }
}
