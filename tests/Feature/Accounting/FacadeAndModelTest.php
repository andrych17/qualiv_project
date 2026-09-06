<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Model relation/scope coverage for paths no Phase-1 controller's eager-load already touches. */
class FacadeAndModelTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_audit_log_is_append_only(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $log = AuditLog::record(['company_id' => $company->id, 'action' => AuditLog::ACTION_MASTER_DATA_CHANGED, 'subject_type' => 'x', 'subject_id' => 1]);

            $this->expectException(LogicException::class);
            $log->update(['action' => AuditLog::ACTION_JOURNAL_POSTED]);
        });
    }

    public function test_audit_log_delete_is_blocked(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $log = AuditLog::record(['company_id' => $company->id, 'action' => AuditLog::ACTION_MASTER_DATA_CHANGED, 'subject_type' => 'x', 'subject_id' => 1]);

            $this->expectException(LogicException::class);
            $log->delete();
        });
    }

    public function test_audit_log_scope_filter_covers_every_facet(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            AuditLog::record([
                'company_id' => $company->id, 'action' => AuditLog::ACTION_JOURNAL_CREATED,
                'subject_type' => 'accounting.gl_journals', 'subject_id' => 42, 'actor_id' => $adminId,
            ]);

            $this->assertSame(1, AuditLog::query()->filter(['company_id' => $company->id])->count());
            $this->assertSame(1, AuditLog::query()->filter(['action' => AuditLog::ACTION_JOURNAL_CREATED])->count());
            $this->assertSame(1, AuditLog::query()->filter(['subject_type' => 'accounting.gl_journals'])->count());
            $this->assertSame(1, AuditLog::query()->filter(['subject_id' => 42])->count());
            $this->assertSame(1, AuditLog::query()->filter(['actor_id' => $adminId])->count());
            $this->assertSame(1, AuditLog::query()->filter(['search' => 'Admin User'])->count());
        });
    }

    public function test_audit_log_record_defaults_actor_and_ip_when_omitted(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            // Called outside of a real HTTP request (no actor_id/ip_address given) — still succeeds.
            $log = AuditLog::record(['company_id' => $company->id, 'action' => AuditLog::ACTION_MASTER_DATA_CHANGED, 'subject_type' => 'x', 'subject_id' => 1]);
            $this->assertNotNull($log->id);
        });
    }

    public function test_company_control_account_and_master_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $arAccount = $this->makeAccount($company, ['is_control_account' => true]);
            $company->update([
                'ar_control_account_id' => $arAccount->id,
                'ap_control_account_id' => $arAccount->id,
                'inventory_control_account_id' => $arAccount->id,
                'payroll_net_pay_payable_account_id' => $arAccount->id,
            ]);
            $company->refresh();

            $this->assertSame($arAccount->id, $company->arControlAccount->id);
            $this->assertSame($arAccount->id, $company->apControlAccount->id);
            $this->assertSame($arAccount->id, $company->inventoryControlAccount->id);
            $this->assertSame($arAccount->id, $company->payrollNetPayPayableAccount->id);

            $fiscalYear = $this->makeFiscalYear($company);
            $this->assertTrue($company->fiscalYears->contains('id', $fiscalYear->id));

            $costCenter = $this->makeCostCenter($company);
            $this->assertTrue($company->costCenters->contains('id', $costCenter->id));

            $this->assertTrue($company->accounts->contains('id', $arAccount->id));
        });
    }

    public function test_account_children_and_journal_lines_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $parent = $this->makeAccount($company);
            $child = $this->makeAccount($company, ['parent_account_id' => $parent->id]);
            $this->assertTrue($parent->children->contains('id', $child->id));
            $this->assertSame($company->id, $parent->company->id);

            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $journal = $this->makeJournal($company, $period, ['debit_account' => $parent]);
            $this->assertCount(1, $parent->glJournalLines);

            $this->assertSame($period->fiscalYear->id, $period->fiscal_year_id);
            $this->assertSame($company->id, $period->company->id);
            $this->assertSame($journal->id, $journal->lines->first()->journal->id);
            $this->assertSame($company->id, $journal->company->id);
            $this->assertSame($company->id, $period->fiscalYear->company->id);
        });
    }

    public function test_cost_center_company_and_parent_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $parent = $this->makeCostCenter($company);
            $child = $this->makeCostCenter($company, ['parent_cost_center_id' => $parent->id]);

            $this->assertSame($company->id, $child->company->id);
            $this->assertSame($parent->id, $child->parent->id);
        });
    }

    public function test_audit_log_company_relation(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $log = AuditLog::record(['company_id' => $company->id, 'action' => AuditLog::ACTION_MASTER_DATA_CHANGED, 'subject_type' => 'x', 'subject_id' => 1]);

            $this->assertSame($company->id, $log->company->id);
        });
    }

    public function test_gl_journal_reversal_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $journal = $this->makeJournal($company, $period);
            app(JournalService::class)->post($journal, $adminId);

            $reversal = app(JournalService::class)->reverse($journal, $adminId);

            $this->assertSame($journal->id, $reversal->reversedJournal->id);
            $this->assertSame($reversal->id, $journal->fresh()->reversal->id);
            $this->assertSame($adminId, $reversal->postedBy->id);
            $this->assertSame($adminId, $reversal->createdBy->id);
        });
    }

    public function test_exchange_rate_and_currency_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $rate = $this->makeExchangeRate($company, ['currency_code' => 'USD']);

            $this->assertSame($company->id, $rate->company->id);
            $this->assertSame('USD', $rate->currency->code);
            $this->assertSame(Currency::class, get_class($rate->currency));
        });
    }

    public function test_control_account_line_error_lists_every_control_account_touched(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $control1 = $this->makeAccount($company, ['is_control_account' => true, 'account_name' => 'AR Control', 'normal_balance' => Account::BALANCE_DEBIT]);
            $control2 = $this->makeAccount($company, ['is_control_account' => true, 'account_name' => 'AP Control', 'normal_balance' => Account::BALANCE_CREDIT]);

            // 'source' must be set explicitly, not left to the DB column's own DEFAULT
            // 'manual' — Eloquent's in-memory model after create() only reflects attributes
            // it was given, not values a DB-level default silently filled in, and
            // JournalService::post()'s control-account guard branches on $journal->source.
            $journal = GlJournal::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $company->id,
                'fiscal_period_id' => $period->id, 'journal_date' => now(), 'currency_code' => 'IDR',
                'source' => GlJournal::SOURCE_MANUAL, 'status' => GlJournal::STATUS_DRAFT,
            ]);
            GlJournalLine::query()->create(['journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $control1->id, 'debit' => 100, 'credit' => 0]);
            GlJournalLine::query()->create(['journal_id' => $journal->id, 'line_no' => 2, 'account_id' => $control2->id, 'debit' => 0, 'credit' => 100]);

            try {
                app(JournalService::class)->post($journal, null);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $message = $e->errors()['lines'][0];
                $this->assertStringContainsString('AR Control', $message);
                $this->assertStringContainsString('AP Control', $message);
            }
        });
    }
}
