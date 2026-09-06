<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Events\JournalPosted;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3C General Ledger / Journal Entries — the single posting path every subledger will eventually go through. */
class JournalTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_create_edit_and_delete_a_draft_journal(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $periodId, $debitAccountId, $creditAccountId] = [null, null, null, null];
        $tenant->run(function () use (&$companyId, &$periodId, &$debitAccountId, &$creditAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $periodId = $this->firstPeriod($this->makeFiscalYear($company))->id;
            $debitAccountId = $this->makeAccount($company, ['normal_balance' => Account::BALANCE_DEBIT])->id;
            $creditAccountId = $this->makeAccount($company, ['normal_balance' => Account::BALANCE_CREDIT])->id;
        });

        $this->get("/accounting/journals?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Journals/Index'));
        $this->get("/accounting/journals/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Journals/Create'));
        // No company_id query param — JournalController::formOptions()'s early-return branch.
        $this->get('/accounting/journals/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('accounts', []));

        $this->post('/accounting/journals', [
            'company_id' => $companyId, 'fiscal_period_id' => $periodId,
            'journal_date' => now()->toDateString(), 'currency_code' => 'IDR', 'memo' => 'Opening entry',
            'lines' => [
                ['account_id' => $debitAccountId, 'debit' => 500000],
                ['account_id' => $creditAccountId, 'credit' => 500000],
            ],
        ]);

        $journalId = null;
        $tenant->run(function () use (&$journalId, $companyId) {
            $journalId = GlJournal::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/journals/{$journalId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Journals/Show')
                ->where('journal.status', GlJournal::STATUS_DRAFT)
                ->has('journal.lines', 2));

        $this->get("/accounting/journals/{$journalId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/Journals/Edit'));

        $this->put("/accounting/journals/{$journalId}", [
            'fiscal_period_id' => $periodId, 'journal_date' => now()->toDateString(),
            'currency_code' => 'IDR', 'memo' => 'Opening entry (revised)',
            'lines' => [
                ['account_id' => $debitAccountId, 'debit' => 750000],
                ['account_id' => $creditAccountId, 'credit' => 750000],
            ],
        ])->assertRedirect(route('accounting.journals.show', $journalId));

        $tenant->run(function () use ($journalId) {
            $journal = GlJournal::query()->find($journalId);
            $this->assertSame('Opening entry (revised)', $journal->memo);
            $this->assertEqualsWithDelta(750000.0, (float) $journal->lines()->where('debit', '>', 0)->value('debit'), 0.01);
        });

        $this->delete("/accounting/journals/{$journalId}")->assertRedirect(route('accounting.journals.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($journalId) {
            $this->assertNull(GlJournal::query()->find($journalId));
        });
    }

    public function test_store_rejects_invalid_company_period_account_and_bad_lines(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $periodId, $accountId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$periodId, &$accountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $periodId = $this->firstPeriod($this->makeFiscalYear($company))->id;
            $accountId = $this->makeAccount($company)->id;
        });

        $this->post('/accounting/journals', [
            'company_id' => 999999, 'fiscal_period_id' => 999999, 'journal_date' => now()->toDateString(),
            'currency_code' => 'IDR', 'lines' => [['account_id' => 999999, 'debit' => 100]],
        ])->assertSessionHasErrors(['company_id', 'fiscal_period_id', 'lines.0.account_id']);

        $this->post('/accounting/journals', [
            'company_id' => $companyId, 'fiscal_period_id' => $periodId, 'journal_date' => now()->toDateString(),
            'currency_code' => 'IDR',
            'lines' => [['account_id' => $accountId, 'debit' => 100, 'credit' => 50]],
        ])->assertSessionHasErrors(['lines.0.debit']);

        $this->post('/accounting/journals', [
            'company_id' => $companyId, 'fiscal_period_id' => $periodId, 'journal_date' => now()->toDateString(),
            'currency_code' => 'IDR',
            'lines' => [['account_id' => $accountId]],
        ])->assertSessionHasErrors(['lines.0.debit']);

        $this->post('/accounting/journals', [
            'company_id' => $companyId, 'fiscal_period_id' => $periodId, 'journal_date' => now()->toDateString(),
            'currency_code' => 'IDR',
            'lines' => [['account_id' => $accountId, 'cost_center_id' => 999999, 'debit' => 100]],
        ])->assertSessionHasErrors(['lines.0.cost_center_id']);
    }

    public function test_update_rejects_invalid_period_account_cost_center_and_bad_lines(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$journalId, $periodId, $accountId] = [null, null, null];
        $tenant->run(function () use (&$journalId, &$periodId, &$accountId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;
            $accountId = $this->makeAccount($company)->id;
            $journalId = $this->makeJournal($company, $period)->id;
        });

        $this->put("/accounting/journals/{$journalId}", [
            'fiscal_period_id' => 999999, 'journal_date' => now()->toDateString(), 'currency_code' => 'IDR',
            'lines' => [['account_id' => $accountId, 'debit' => 100]],
        ])->assertSessionHasErrors(['fiscal_period_id']);

        $this->put("/accounting/journals/{$journalId}", [
            'fiscal_period_id' => $periodId, 'journal_date' => now()->toDateString(), 'currency_code' => 'IDR',
            'lines' => [['account_id' => 999999, 'debit' => 100]],
        ])->assertSessionHasErrors(['lines.0.account_id']);

        $this->put("/accounting/journals/{$journalId}", [
            'fiscal_period_id' => $periodId, 'journal_date' => now()->toDateString(), 'currency_code' => 'IDR',
            'lines' => [['account_id' => $accountId, 'cost_center_id' => 999999, 'debit' => 100]],
        ])->assertSessionHasErrors(['lines.0.cost_center_id']);

        $this->put("/accounting/journals/{$journalId}", [
            'fiscal_period_id' => $periodId, 'journal_date' => now()->toDateString(), 'currency_code' => 'IDR',
            'lines' => [['account_id' => $accountId, 'debit' => 100, 'credit' => 50]],
        ])->assertSessionHasErrors(['lines.0.debit']);

        $this->put("/accounting/journals/{$journalId}", [
            'fiscal_period_id' => $periodId, 'journal_date' => now()->toDateString(), 'currency_code' => 'IDR',
            'lines' => [['account_id' => $accountId]],
        ])->assertSessionHasErrors(['lines.0.debit']);
    }

    public function test_only_a_draft_journal_can_be_edited_or_deleted(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$journalId, $periodId, $accountId] = [null, null, null];
        $tenant->run(function () use (&$journalId, &$periodId, &$accountId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;
            $accountId = $this->makeAccount($company)->id;
            $journal = $this->makeJournal($company, $period, ['status' => GlJournal::STATUS_POSTED, 'posted_at' => now()]);
            $journalId = $journal->id;
        });

        // Lines must be non-empty and balanced to pass UpdateJournalRequest's own
        // validation and actually reach JournalService's draft-only status guard.
        $this->put("/accounting/journals/{$journalId}", [
            'fiscal_period_id' => $periodId, 'journal_date' => now()->toDateString(), 'currency_code' => 'IDR',
            'lines' => [['account_id' => $accountId, 'debit' => 100, 'credit' => 0]],
        ])->assertSessionHasErrors(['status']);

        $this->delete("/accounting/journals/{$journalId}")->assertSessionHasErrors(['status']);
    }

    public function test_admin_can_post_a_balanced_journal(): void
    {
        Event::fake([JournalPosted::class]);
        $tenant = $this->loginAsAccountingAdmin();

        $journalId = null;
        $tenant->run(function () use (&$journalId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $journalId = $this->makeJournal($company, $period)->id;
        });

        $this->post("/accounting/journals/{$journalId}/post")->assertRedirect(route('accounting.journals.show', $journalId));

        $tenant->run(function () use ($journalId) {
            $journal = GlJournal::query()->find($journalId);
            $this->assertSame(GlJournal::STATUS_POSTED, $journal->status);
            $this->assertNotNull($journal->posted_at);
            $this->assertSame(1, AuditLog::query()->where('subject_id', $journalId)->where('action', AuditLog::ACTION_JOURNAL_POSTED)->count());
        });

        Event::assertDispatched(JournalPosted::class, fn (JournalPosted $e) => $e->journalId === $journalId);
    }

    public function test_post_rejects_a_journal_with_no_lines(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $journalId = null;
        $tenant->run(function () use (&$journalId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $journalId = $this->makeJournal($company, $period, ['skip_lines' => true])->id;
        });

        $this->post("/accounting/journals/{$journalId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_post_rejects_an_unbalanced_journal(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $journalId = null;
        $tenant->run(function () use (&$journalId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $journal = $this->makeJournal($company, $period, ['skip_lines' => true]);
            $account = $this->makeAccount($company);
            GlJournalLine::query()->create(['journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $account->id, 'debit' => 100, 'credit' => 0]);
            GlJournalLine::query()->create(['journal_id' => $journal->id, 'line_no' => 2, 'account_id' => $this->makeAccount($company)->id, 'debit' => 0, 'credit' => 50]);
            $journalId = $journal->id;
        });

        $this->post("/accounting/journals/{$journalId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_post_rejects_a_manual_journal_touching_a_control_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $journalId = null;
        $tenant->run(function () use (&$journalId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $controlAccount = $this->makeAccount($company, ['is_control_account' => true, 'normal_balance' => Account::BALANCE_DEBIT]);
            $journal = $this->makeJournal($company, $period, ['debit_account' => $controlAccount]);
            $journalId = $journal->id;
        });

        $this->post("/accounting/journals/{$journalId}/post")->assertSessionHasErrors(['lines']);
    }

    public function test_a_non_manual_source_journal_may_post_a_control_account_line(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $journalId = null;
        $tenant->run(function () use (&$journalId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $controlAccount = $this->makeAccount($company, ['is_control_account' => true, 'normal_balance' => Account::BALANCE_DEBIT]);
            $journal = $this->makeJournal($company, $period, ['debit_account' => $controlAccount, 'source' => 'ar']);
            $journalId = $journal->id;
        });

        $tenant->run(function () use ($journalId) {
            app(JournalService::class)->post(GlJournal::query()->find($journalId), null);
            $this->assertSame(GlJournal::STATUS_POSTED, GlJournal::query()->find($journalId)->status);
        });
    }

    public function test_post_rejects_when_the_fiscal_period_is_not_open(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $journalId = null;
        $tenant->run(function () use (&$journalId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $period->update(['status' => FiscalPeriod::STATUS_HARD_CLOSED]);
            $journalId = $this->makeJournal($company, $period)->id;
        });

        $this->post("/accounting/journals/{$journalId}/post")->assertSessionHasErrors(['fiscal_period_id']);
    }

    public function test_post_rejects_when_already_posted(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $journalId = null;
        $tenant->run(function () use (&$journalId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $journalId = $this->makeJournal($company, $period, ['status' => GlJournal::STATUS_POSTED, 'posted_at' => now()])->id;
        });

        $this->post("/accounting/journals/{$journalId}/post")->assertSessionHasErrors(['status']);
    }

    public function test_admin_can_reverse_a_posted_journal(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$journalId, $debitAccountId, $creditAccountId] = [null, null, null];
        $tenant->run(function () use (&$journalId, &$debitAccountId, &$creditAccountId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $debitAccount = $this->makeAccount($company, ['normal_balance' => Account::BALANCE_DEBIT]);
            $creditAccount = $this->makeAccount($company, ['normal_balance' => Account::BALANCE_CREDIT]);
            $debitAccountId = $debitAccount->id;
            $creditAccountId = $creditAccount->id;
            $journal = $this->makeJournal($company, $period, ['debit_account' => $debitAccount, 'credit_account' => $creditAccount, 'amount' => 200000]);
            app(JournalService::class)->post($journal, null);
            $journalId = $journal->id;
        });

        $this->post("/accounting/journals/{$journalId}/reverse")->assertRedirect();

        $tenant->run(function () use ($journalId, $debitAccountId, $creditAccountId) {
            $original = GlJournal::query()->find($journalId);
            $this->assertSame(GlJournal::STATUS_REVERSED, $original->status);

            $reversal = GlJournal::query()->where('reversed_journal_id', $journalId)->first();
            $this->assertNotNull($reversal);
            $this->assertSame(GlJournal::STATUS_POSTED, $reversal->status);

            // Debit/credit swapped on every line.
            $reversalDebitLine = $reversal->lines()->where('account_id', $creditAccountId)->first();
            $this->assertEqualsWithDelta(200000.0, (float) $reversalDebitLine->debit, 0.01);
            $reversalCreditLine = $reversal->lines()->where('account_id', $debitAccountId)->first();
            $this->assertEqualsWithDelta(200000.0, (float) $reversalCreditLine->credit, 0.01);

            $this->assertSame(1, AuditLog::query()->where('subject_id', $journalId)->where('action', AuditLog::ACTION_JOURNAL_REVERSED)->count());
        });
    }

    public function test_reverse_rejects_a_non_posted_journal(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $journalId = null;
        $tenant->run(function () use (&$journalId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $journalId = $this->makeJournal($company, $period)->id;
        });

        $this->post("/accounting/journals/{$journalId}/reverse")->assertSessionHasErrors(['status']);
    }

    /**
     * reverse() falls back to whatever period is currently open covering today's date when
     * the original journal's own period is no longer open — otherwise a journal posted months
     * ago could never be reversed once its own period is closed for month-end.
     */
    public function test_reverse_falls_back_to_the_currently_open_period_when_the_original_is_closed(): void
    {
        // Freeze "today" to a fixed mid-year date so the fiscal year's own January (period_no
        // 1, the one this test closes) is never accidentally the same period "today" falls in.
        Carbon::setTestNow('2026-06-15');

        $tenant = $this->loginAsAccountingAdmin();

        $journalId = null;
        $tenant->run(function () use (&$journalId) {
            $company = $this->makeCompany(['fiscal_year_start_month' => 1]);
            $fiscalYear = $this->makeFiscalYear($company, ['year' => 2026, 'start_date' => '2026-01-01']);
            $originalPeriod = $this->firstPeriod($fiscalYear);
            $journal = $this->makeJournal($company, $originalPeriod);
            app(JournalService::class)->post($journal, null);

            // Close the original (January) period, but the period covering "today" (June) stays open.
            $originalPeriod->update(['status' => FiscalPeriod::STATUS_HARD_CLOSED]);
            $journalId = $journal->id;
        });

        $this->post("/accounting/journals/{$journalId}/reverse")->assertRedirect();

        $tenant->run(function () use ($journalId) {
            $reversal = GlJournal::query()->where('reversed_journal_id', $journalId)->first();
            $this->assertNotNull($reversal);
            $currentPeriod = FiscalPeriod::query()->find($reversal->fiscal_period_id);
            $this->assertSame(FiscalPeriod::STATUS_OPEN, $currentPeriod->status);
            $this->assertSame(6, $currentPeriod->period_no);
        });

        Carbon::setTestNow();
    }

    public function test_journal_index_filters_by_company(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeJournal($company, $this->firstPeriod($this->makeFiscalYear($company)));
        });

        $this->get("/accounting/journals?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('journals', 1));
    }

    /** StoreJournalRequest/UpdateJournalRequest both already reject a both-debit-and-credit or neither line, so JournalService::replaceLines()'s own copy of that guard is unreachable via HTTP. */
    public function test_service_layer_rejects_malformed_lines_bypassing_form_request(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $account = $this->makeAccount($company);
            $service = app(JournalService::class);

            $this->expectException(ValidationException::class);
            $service->create(
                ['company_id' => $company->id, 'fiscal_period_id' => $period->id, 'journal_date' => now()->toDateString(), 'currency_code' => 'IDR'],
                [['account_id' => $account->id, 'debit' => 100, 'credit' => 100]],
                null,
            );
        });
    }

    public function test_service_layer_rejects_a_line_with_neither_debit_nor_credit(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $account = $this->makeAccount($company);
            $service = app(JournalService::class);

            $this->expectException(ValidationException::class);
            $service->create(
                ['company_id' => $company->id, 'fiscal_period_id' => $period->id, 'journal_date' => now()->toDateString(), 'currency_code' => 'IDR'],
                [['account_id' => $account->id]],
                null,
            );
        });
    }

    public function test_create_defaults_to_manual_source_and_records_audit_entry(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $account1 = $this->makeAccount($company);
            $account2 = $this->makeAccount($company);

            $journal = app(JournalService::class)->create(
                ['company_id' => $company->id, 'fiscal_period_id' => $period->id, 'journal_date' => now()->toDateString(), 'currency_code' => 'IDR'],
                [['account_id' => $account1->id, 'debit' => 10], ['account_id' => $account2->id, 'credit' => 10]],
                null,
            );

            $this->assertSame(GlJournal::SOURCE_MANUAL, $journal->source);
            $this->assertSame(1, AuditLog::query()->where('subject_id', $journal->id)->where('action', AuditLog::ACTION_JOURNAL_CREATED)->count());
        });
    }
}
