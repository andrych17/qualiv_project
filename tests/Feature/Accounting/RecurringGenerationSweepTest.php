<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\RecurringGenerationLog;
use App\Modules\Accounting\Services\RecurringArTemplateService;
use App\Modules\Accounting\Services\RecurringGenerationService;
use App\Modules\Accounting\Services\RecurringJournalTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3P — the generation sweep: drafts (never posts) a journal/invoice for every due template, run via `accounting:run-recurring-sweep` in production, called directly here. */
class RecurringGenerationSweepTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_sweep_drafts_a_journal_and_advances_next_run_date(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $debit = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $credit = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            $template = app(RecurringJournalTemplateService::class)->create(
                ['company_id' => $company->id, 'name' => 'Rent', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                [['account_id' => $debit->id, 'debit' => 1000000], ['account_id' => $credit->id, 'credit' => 1000000]],
                $this->adminUserId(),
            );

            $summary = app(RecurringGenerationService::class)->runDue(Carbon::parse('2026-01-05'));

            $this->assertSame(1, $summary['journals_generated']);
            $this->assertSame(0, $summary['skipped_no_period']);
            $this->assertSame(0, $summary['deactivated']);

            $fresh = $template->fresh();
            $this->assertSame('2026-01-05', $fresh->last_run_date->toDateString());
            $this->assertSame('2026-02-05', $fresh->next_run_date->toDateString());

            $log = RecurringGenerationLog::query()->where('template_type', RecurringGenerationLog::TYPE_JOURNAL)->where('template_id', $template->id)->first();
            $this->assertNotNull($log);
            $this->assertSame('accounting.gl_journals', $log->generated_subject_type);

            $journal = GlJournal::query()->find($log->generated_subject_id);
            $this->assertNotNull($journal);
            $this->assertSame(GlJournal::STATUS_DRAFT, $journal->status);
            $this->assertSame('accounting.recurring_journal_templates', $journal->subject_type);
        });
    }

    public function test_sweep_drafts_an_ar_invoice_and_advances_next_run_date(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            $template = app(RecurringArTemplateService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'name' => 'Retainer', 'currency_code' => 'IDR', 'invoice_type' => ArInvoice::TYPE_STANDARD, 'payment_terms_days' => 30, 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                [['description' => 'Retainer', 'qty' => 1, 'unit_price' => 5000000, 'revenue_account_id' => $revenueAccount->id]],
                $this->adminUserId(),
            );

            $summary = app(RecurringGenerationService::class)->runDue(Carbon::parse('2026-01-05'));

            $this->assertSame(1, $summary['invoices_generated']);

            $fresh = $template->fresh();
            $this->assertSame('2026-02-05', $fresh->next_run_date->toDateString());

            $log = RecurringGenerationLog::query()->where('template_type', RecurringGenerationLog::TYPE_AR_INVOICE)->where('template_id', $template->id)->first();
            $invoice = ArInvoice::query()->find($log->generated_subject_id);
            $this->assertNotNull($invoice);
            $this->assertSame(ArInvoice::STATUS_DRAFT, $invoice->status);
            $this->assertSame('2026-02-04', $invoice->due_date->toDateString());
        });
    }

    /** A journal template due, but the company has no open fiscal period covering the run date — the sweep stalls (doesn't advance) so the next run retries. */
    public function test_sweep_skips_a_journal_template_with_no_open_fiscal_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            // No fiscal year at all -> no period ever covers the run date.
            $debit = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $credit = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            $template = app(RecurringJournalTemplateService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                [['account_id' => $debit->id, 'debit' => 100], ['account_id' => $credit->id, 'credit' => 100]],
                $this->adminUserId(),
            );

            $summary = app(RecurringGenerationService::class)->runDue(Carbon::parse('2026-01-05'));

            $this->assertSame(0, $summary['journals_generated']);
            $this->assertSame(1, $summary['skipped_no_period']);
            $this->assertSame('2026-01-05', $template->fresh()->next_run_date->toDateString());
            $this->assertSame(0, RecurringGenerationLog::query()->count());
        });
    }

    /** A COUNT=1 rule has no occurrence after its own anchor -> the template deactivates itself once that single occurrence is generated. */
    public function test_sweep_deactivates_a_template_once_its_rule_is_exhausted(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $debit = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $credit = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            $template = app(RecurringJournalTemplateService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY;COUNT=1', 'anchor_date' => '2026-01-05'],
                [['account_id' => $debit->id, 'debit' => 100], ['account_id' => $credit->id, 'credit' => 100]],
                $this->adminUserId(),
            );

            $summary = app(RecurringGenerationService::class)->runDue(Carbon::parse('2026-01-05'));

            $this->assertSame(1, $summary['journals_generated']);
            $this->assertSame(1, $summary['deactivated']);

            $fresh = $template->fresh();
            $this->assertNull($fresh->next_run_date);
            $this->assertFalse($fresh->is_active);
        });
    }

    /** Same COUNT=1-exhaustion shape as the journal-template test above, but for the AR template's own advanceArTemplate() deactivation branch. */
    public function test_sweep_deactivates_an_ar_template_once_its_rule_is_exhausted(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            $template = app(RecurringArTemplateService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'name' => 'X', 'currency_code' => 'IDR', 'invoice_type' => ArInvoice::TYPE_STANDARD, 'payment_terms_days' => 30, 'recurrence_rule' => 'FREQ=MONTHLY;COUNT=1', 'anchor_date' => '2026-01-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]],
                $this->adminUserId(),
            );

            $summary = app(RecurringGenerationService::class)->runDue(Carbon::parse('2026-01-05'));

            $this->assertSame(1, $summary['invoices_generated']);
            $this->assertSame(1, $summary['deactivated']);

            $fresh = $template->fresh();
            $this->assertNull($fresh->next_run_date);
            $this->assertFalse($fresh->is_active);
        });
    }

    /** AR mirror of test_sweep_only_advances_when_the_occurrence_was_already_logged() (journal templates) — advanceArTemplate()'s own already-generated branch. */
    public function test_sweep_only_advances_an_ar_template_when_the_occurrence_was_already_logged(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            $template = app(RecurringArTemplateService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'name' => 'X', 'currency_code' => 'IDR', 'invoice_type' => ArInvoice::TYPE_STANDARD, 'payment_terms_days' => 30, 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]],
                $this->adminUserId(),
            );

            RecurringGenerationLog::query()->create([
                'template_type' => RecurringGenerationLog::TYPE_AR_INVOICE, 'template_id' => $template->id, 'run_date' => '2026-01-05',
                'generated_subject_type' => 'accounting.ar_invoices', 'generated_subject_id' => 999999,
            ]);

            $summary = app(RecurringGenerationService::class)->runDue(Carbon::parse('2026-01-05'));

            $this->assertSame(0, $summary['invoices_generated']);
            $this->assertSame('2026-02-05', $template->fresh()->next_run_date->toDateString());
            $this->assertSame(1, RecurringGenerationLog::query()->count());
        });
    }

    /** anchor is 3 months behind $asOf with an unbounded monthly rule -> the sweep catches up all 3 occurrences in one run, capped by MAX_CATCHUP_PER_RUN. */
    public function test_sweep_catches_up_multiple_overdue_occurrences_in_one_run(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $fiscalYear = $this->makeFiscalYear($company);
            $debit = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $credit = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            app(RecurringJournalTemplateService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                [['account_id' => $debit->id, 'debit' => 100], ['account_id' => $credit->id, 'credit' => 100]],
                $this->adminUserId(),
            );

            // As of April 5th, January/February/March/April occurrences are all due (4 total).
            $summary = app(RecurringGenerationService::class)->runDue(Carbon::parse('2026-04-05'));

            $this->assertSame(4, $summary['journals_generated']);
            $this->assertSame(4, RecurringGenerationLog::query()->count());
        });
    }

    /** A pre-existing generation-log row for the template's current next_run_date (simulating a run that logged but crashed before advancing) is detected and only advances — it does not draft a second journal. */
    public function test_sweep_only_advances_when_the_occurrence_was_already_logged(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $debit = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $credit = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            $template = app(RecurringJournalTemplateService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                [['account_id' => $debit->id, 'debit' => 100], ['account_id' => $credit->id, 'credit' => 100]],
                $this->adminUserId(),
            );

            RecurringGenerationLog::query()->create([
                'template_type' => RecurringGenerationLog::TYPE_JOURNAL, 'template_id' => $template->id, 'run_date' => '2026-01-05',
                'generated_subject_type' => 'accounting.gl_journals', 'generated_subject_id' => 999999,
            ]);

            $summary = app(RecurringGenerationService::class)->runDue(Carbon::parse('2026-01-05'));

            $this->assertSame(0, $summary['journals_generated']);
            $this->assertSame('2026-02-05', $template->fresh()->next_run_date->toDateString());
            $this->assertSame(1, RecurringGenerationLog::query()->count());
        });
    }

    public function test_console_command_runs_the_sweep(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company, ['start_date' => now()->startOfMonth()->toDateString()]);
            $debit = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $credit = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            app(RecurringJournalTemplateService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => now()->toDateString()],
                [['account_id' => $debit->id, 'debit' => 100], ['account_id' => $credit->id, 'credit' => 100]],
                $this->adminUserId(),
            );

            $this->artisan('accounting:run-recurring-sweep')->assertExitCode(0);

            $this->assertSame(1, RecurringGenerationLog::query()->count());
        });
    }
}
