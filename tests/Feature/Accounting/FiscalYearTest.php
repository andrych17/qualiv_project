<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\FiscalYearService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3B fiscal calendar (always 12 periods) and §3O period locking (open/soft/hard, audit-classified as close vs. reopen). */
class FiscalYearTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_create_a_fiscal_year_with_twelve_periods(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/fiscal-years?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/FiscalYears/Index'));
        $this->get("/accounting/fiscal-years/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/FiscalYears/Create'));

        $this->post('/accounting/fiscal-years', ['company_id' => $companyId, 'year' => 2026, 'start_date' => '2026-01-01'])
            ->assertRedirect(route('accounting.fiscal-years.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($companyId) {
            $fiscalYear = FiscalYear::query()->where('company_id', $companyId)->where('year', 2026)->first();
            $this->assertNotNull($fiscalYear);
            $this->assertSame('2026-12-31', $fiscalYear->end_date->toDateString());
            $this->assertSame(12, FiscalPeriod::query()->where('fiscal_year_id', $fiscalYear->id)->count());

            $period1 = FiscalPeriod::query()->where('fiscal_year_id', $fiscalYear->id)->where('period_no', 1)->first();
            $this->assertSame('2026-01-01', $period1->start_date->toDateString());
            $this->assertSame('2026-01-31', $period1->end_date->toDateString());

            $period12 = FiscalPeriod::query()->where('fiscal_year_id', $fiscalYear->id)->where('period_no', 12)->first();
            $this->assertSame('2026-12-01', $period12->start_date->toDateString());
            $this->assertSame('2026-12-31', $period12->end_date->toDateString());
        });
    }

    public function test_store_rejects_invalid_company_and_duplicate_year(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeFiscalYear($company, ['year' => 2026]);
        });

        $this->post('/accounting/fiscal-years', ['company_id' => 999999, 'year' => 2026, 'start_date' => '2026-01-01'])
            ->assertSessionHasErrors(['company_id']);

        $this->post('/accounting/fiscal-years', ['company_id' => $companyId, 'year' => 2026, 'start_date' => '2026-01-01'])
            ->assertSessionHasErrors(['year']);
    }

    public function test_fiscal_year_index_shows_periods_grouped_under_each_year(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeFiscalYear($company, ['year' => 2026]);
        });

        $this->get("/accounting/fiscal-years?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('fiscalYears', 1)->has('fiscalYears.0.periods', 12));
    }

    public function test_admin_can_close_and_reopen_a_period_with_correct_audit_classification(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $periodId = null;
        $tenant->run(function () use (&$periodId) {
            $company = $this->makeCompany();
            $periodId = $this->firstPeriod($this->makeFiscalYear($company))->id;
        });

        $this->put("/accounting/fiscal-periods/{$periodId}/status", ['status' => FiscalPeriod::STATUS_SOFT_CLOSED])
            ->assertRedirect();

        $tenant->run(function () use ($periodId) {
            $period = FiscalPeriod::query()->find($periodId);
            $this->assertSame(FiscalPeriod::STATUS_SOFT_CLOSED, $period->status);
            $this->assertSame(1, AuditLog::query()->where('subject_id', $periodId)->where('action', AuditLog::ACTION_PERIOD_CLOSED)->count());
        });

        $this->put("/accounting/fiscal-periods/{$periodId}/status", ['status' => FiscalPeriod::STATUS_HARD_CLOSED])
            ->assertRedirect();
        $tenant->run(function () use ($periodId) {
            $this->assertSame(2, AuditLog::query()->where('subject_id', $periodId)->where('action', AuditLog::ACTION_PERIOD_CLOSED)->count());
        });

        $this->put("/accounting/fiscal-periods/{$periodId}/status", ['status' => FiscalPeriod::STATUS_OPEN])
            ->assertRedirect();
        $tenant->run(function () use ($periodId) {
            $period = FiscalPeriod::query()->find($periodId);
            $this->assertSame(FiscalPeriod::STATUS_OPEN, $period->status);
            $this->assertSame(1, AuditLog::query()->where('subject_id', $periodId)->where('action', AuditLog::ACTION_PERIOD_REOPENED)->count());
        });
    }

    public function test_setting_the_same_status_logs_no_audit_entry(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $periodId = null;
        $tenant->run(function () use (&$periodId) {
            $company = $this->makeCompany();
            $periodId = $this->firstPeriod($this->makeFiscalYear($company))->id;
        });

        $this->put("/accounting/fiscal-periods/{$periodId}/status", ['status' => FiscalPeriod::STATUS_OPEN])->assertRedirect();

        $tenant->run(function () use ($periodId) {
            $this->assertSame(0, AuditLog::query()->where('subject_id', $periodId)->count());
        });
    }

    /**
     * §3O guard: reopening a period is blocked once a LATER fiscal year is already non-open —
     * regression test for a bug found while reading source (FiscalYearService referenced the
     * non-existent FiscalYear::STATUS_CLOSED constant, which would fatal every reopen attempt
     * that reached this branch; fixed to check `status != STATUS_OPEN`).
     */
    public function test_reopening_a_period_is_blocked_when_a_later_fiscal_year_is_closed(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $periodId = null;
        $tenant->run(function () use (&$periodId) {
            $company = $this->makeCompany();
            $year2026 = $this->makeFiscalYear($company, ['year' => 2026]);
            $periodId = $this->firstPeriod($year2026)->id;

            $year2026->update(['status' => FiscalYear::STATUS_SOFT_CLOSED]);
            $this->makeFiscalYear($company, ['year' => 2027, 'status' => FiscalYear::STATUS_HARD_CLOSED]);
        });

        // Soft-close then attempt to reopen the 2026 period — 2027 is already closed, so this must be blocked.
        $this->put("/accounting/fiscal-periods/{$periodId}/status", ['status' => FiscalPeriod::STATUS_SOFT_CLOSED])->assertRedirect();
        $this->put("/accounting/fiscal-periods/{$periodId}/status", ['status' => FiscalPeriod::STATUS_OPEN])
            ->assertSessionHasErrors(['status']);

        $tenant->run(function () use ($periodId) {
            $this->assertSame(FiscalPeriod::STATUS_SOFT_CLOSED, FiscalPeriod::query()->find($periodId)->status);
        });
    }

    public function test_reopening_is_allowed_when_no_later_fiscal_year_exists_or_is_still_open(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $periodId = null;
        $tenant->run(function () use (&$periodId) {
            $company = $this->makeCompany();
            $year = $this->makeFiscalYear($company, ['year' => 2026]);
            $periodId = $this->firstPeriod($year)->id;
        });

        $this->put("/accounting/fiscal-periods/{$periodId}/status", ['status' => FiscalPeriod::STATUS_HARD_CLOSED])->assertRedirect();
        $this->put("/accounting/fiscal-periods/{$periodId}/status", ['status' => FiscalPeriod::STATUS_OPEN])->assertRedirect();

        $tenant->run(function () use ($periodId) {
            $this->assertSame(FiscalPeriod::STATUS_OPEN, FiscalPeriod::query()->find($periodId)->status);
        });
    }

    /** UpdatePeriodStatusRequest already validates `status` via `in:`, so setPeriodStatus()'s own invalid-status guard is unreachable via HTTP — direct service call needed. */
    public function test_service_layer_rejects_an_invalid_status_bypassing_form_request(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));

            $this->expectException(ValidationException::class);
            app(FiscalYearService::class)->setPeriodStatus($period, 'not_a_real_status');
        });
    }
}
