<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\BankStatementImport;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\GlJournalLine;
use App\Modules\Accounting\Services\BankReconciliationService;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3Q Bank Reconciliation — matches staged CSV statement lines against posted GL journal lines on the bank's own GL account. */
class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    private function makeStatementLine(BankAccount $bankAccount, array $attrs = []): BankStatementLine
    {
        $import = BankStatementImport::query()->create([
            'company_id' => $bankAccount->company_id, 'bank_account_id' => $bankAccount->id,
            'object_key' => 'k', 'original_filename' => 's.csv', 'line_count' => 1, 'imported_at' => now(),
        ]);

        return BankStatementLine::query()->create([
            'import_id' => $import->id,
            'line_date' => $attrs['line_date'] ?? '2026-01-10',
            'description' => $attrs['description'] ?? 'Statement line',
            'amount' => $attrs['amount'] ?? 100000,
            'reference' => $attrs['reference'] ?? null,
            'status' => BankStatementLine::STATUS_UNMATCHED,
        ]);
    }

    public function test_show_page_for_a_base_currency_account_lists_lines_and_worksheet(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$bankAccountId, $statementLineId, $journalLineId] = [null, null, null];
        $tenant->run(function () use (&$bankAccountId, &$statementLineId, &$journalLineId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);
            $bankAccountId = $bankAccount->id;

            $statementLineId = $this->makeStatementLine($bankAccount, ['amount' => 100000])->id;

            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $journal = $this->makeJournal($company, $period, ['debit_account' => $glAccount, 'credit_account' => $offsetAccount, 'amount' => 200000]);
            app(JournalService::class)->post($journal, $this->adminUserId());
            $journalLineId = $journal->fresh()->lines()->where('account_id', $glAccount->id)->value('id');
        });

        $this->get("/accounting/bank-reconciliation/{$bankAccountId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/BankReconciliation/Show')
                ->where('unsupported', false)
                ->has('unmatchedStatementLines', 1)
                ->has('unmatchedJournalLines', 1)
                ->where('worksheet.outstandingBookItems', 1)
                ->where('worksheet.unclearedStatementItems', 1));
    }

    public function test_show_page_for_a_foreign_currency_account_is_unsupported(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $bankAccountId = null;
        $tenant->run(function () use (&$bankAccountId) {
            $company = $this->makeCompany(['base_currency' => 'IDR']);
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01', 'rate_to_base' => 15000]);
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccountId = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'USD', 'currency_code' => 'USD', 'gl_account_id' => $glAccount->id])->id;
        });

        $this->get("/accounting/bank-reconciliation/{$bankAccountId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('unsupported', true)->where('worksheet', null));
    }

    public function test_auto_match_commits_only_unambiguous_exact_pairs(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$bankAccountId, $exactLineId, $ambiguousLineId, $noCandidateLineId] = [null, null, null, null];
        $tenant->run(function () use (&$bankAccountId, &$exactLineId, &$ambiguousLineId, &$noCandidateLineId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);
            $bankAccountId = $bankAccount->id;
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            // Unambiguous exact match: one statement line, one journal line, same amount.
            $exactLineId = $this->makeStatementLine($bankAccount, ['amount' => 100000, 'line_date' => '2026-01-10'])->id;
            $journalExact = $this->makeJournal($company, $period, ['debit_account' => $glAccount, 'credit_account' => $offsetAccount, 'amount' => 100000, 'journal_date' => '2026-01-10']);
            app(JournalService::class)->post($journalExact, $this->adminUserId());

            // Ambiguous: two journal lines share the same amount as this statement line.
            $ambiguousLineId = $this->makeStatementLine($bankAccount, ['amount' => 50000, 'line_date' => '2026-01-12'])->id;
            $journalAmbiguous1 = $this->makeJournal($company, $period, ['debit_account' => $glAccount, 'credit_account' => $offsetAccount, 'amount' => 50000, 'journal_date' => '2026-01-12']);
            app(JournalService::class)->post($journalAmbiguous1, $this->adminUserId());
            $journalAmbiguous2 = $this->makeJournal($company, $period, ['debit_account' => $glAccount, 'credit_account' => $offsetAccount, 'amount' => 50000, 'journal_date' => '2026-01-13']);
            app(JournalService::class)->post($journalAmbiguous2, $this->adminUserId());

            // No candidate at all.
            $noCandidateLineId = $this->makeStatementLine($bankAccount, ['amount' => 999999, 'line_date' => '2026-01-10'])->id;
        });

        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/auto-match")->assertRedirect();

        $tenant->run(function () use ($exactLineId, $ambiguousLineId, $noCandidateLineId) {
            $this->assertSame(BankStatementLine::STATUS_MATCHED, BankStatementLine::query()->find($exactLineId)->status);
            $this->assertSame(BankStatementLine::STATUS_UNMATCHED, BankStatementLine::query()->find($ambiguousLineId)->status);
            $this->assertSame(BankStatementLine::STATUS_UNMATCHED, BankStatementLine::query()->find($noCandidateLineId)->status);
        });
    }

    /** journalLineDelta()'s credit-normal branch — a debit-normal GL account is used by every other test in this suite. */
    public function test_auto_match_works_for_a_credit_normal_bank_gl_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$bankAccountId, $lineId] = [null, null];
        $tenant->run(function () use (&$bankAccountId, &$lineId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Credit Line', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);
            $bankAccountId = $bankAccount->id;
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);

            $lineId = $this->makeStatementLine($bankAccount, ['amount' => 100000, 'line_date' => '2026-01-10'])->id;
            // Credit-normal balance increases with a CREDIT to the bank's own GL account.
            $journal = $this->makeJournal($company, $period, ['debit_account' => $offsetAccount, 'credit_account' => $glAccount, 'amount' => 100000, 'journal_date' => '2026-01-10']);
            app(JournalService::class)->post($journal, $this->adminUserId());
        });

        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/auto-match")->assertRedirect();

        $tenant->run(function () use ($lineId) {
            $this->assertSame(BankStatementLine::STATUS_MATCHED, BankStatementLine::query()->find($lineId)->status);
        });
    }

    public function test_manual_match_rejects_wrong_account_unposted_journal_amount_mismatch_and_already_matched(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$bankAccountId, $lineId, $wrongAccountJournalLineId, $draftJournalLineId, $mismatchedJournalLineId, $correctJournalLineId] = [null, null, null, null, null, null];
        $tenant->run(function () use (&$bankAccountId, &$lineId, &$wrongAccountJournalLineId, &$draftJournalLineId, &$mismatchedJournalLineId, &$correctJournalLineId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $otherAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);
            $bankAccountId = $bankAccount->id;
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            $lineId = $this->makeStatementLine($bankAccount, ['amount' => 100000])->id;

            // Journal line on a DIFFERENT account.
            $wrongJournal = $this->makeJournal($company, $period, ['debit_account' => $otherAccount, 'credit_account' => $offsetAccount, 'amount' => 100000]);
            app(JournalService::class)->post($wrongJournal, $this->adminUserId());
            $wrongAccountJournalLineId = $wrongJournal->fresh()->lines()->where('account_id', $otherAccount->id)->value('id');

            // Draft (unposted) journal on the right account.
            $draftJournal = $this->makeJournal($company, $period, ['debit_account' => $glAccount, 'credit_account' => $offsetAccount, 'amount' => 100000]);
            $draftJournalLineId = $draftJournal->lines()->where('account_id', $glAccount->id)->value('id');

            // Posted, right account, WRONG amount.
            $mismatchedJournal = $this->makeJournal($company, $period, ['debit_account' => $glAccount, 'credit_account' => $offsetAccount, 'amount' => 500000]);
            app(JournalService::class)->post($mismatchedJournal, $this->adminUserId());
            $mismatchedJournalLineId = $mismatchedJournal->fresh()->lines()->where('account_id', $glAccount->id)->value('id');

            // Posted, right account, right amount — the one that should actually succeed.
            $correctJournal = $this->makeJournal($company, $period, ['debit_account' => $glAccount, 'credit_account' => $offsetAccount, 'amount' => 100000]);
            app(JournalService::class)->post($correctJournal, $this->adminUserId());
            $correctJournalLineId = $correctJournal->fresh()->lines()->where('account_id', $glAccount->id)->value('id');
        });

        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/match", ['statement_line_id' => $lineId, 'journal_line_id' => $wrongAccountJournalLineId])
            ->assertSessionHasErrors(['journal_line']);
        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/match", ['statement_line_id' => $lineId, 'journal_line_id' => $draftJournalLineId])
            ->assertSessionHasErrors(['journal_line']);
        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/match", ['statement_line_id' => $lineId, 'journal_line_id' => $mismatchedJournalLineId])
            ->assertSessionHasErrors(['journal_line']);

        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/match", ['statement_line_id' => $lineId, 'journal_line_id' => $correctJournalLineId])
            ->assertRedirect();

        // Already matched — re-matching the same (now-matched) statement line is rejected.
        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/match", ['statement_line_id' => $lineId, 'journal_line_id' => $correctJournalLineId])
            ->assertSessionHasErrors(['statement_line']);
    }

    public function test_match_rejects_a_journal_line_already_claimed_by_another_statement_line(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$bankAccountId, $lineAId, $lineBId, $journalLineId] = [null, null, null, null];
        $tenant->run(function () use (&$bankAccountId, &$lineAId, &$lineBId, &$journalLineId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);
            $bankAccountId = $bankAccount->id;
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            $lineAId = $this->makeStatementLine($bankAccount, ['amount' => 100000])->id;
            $lineBId = $this->makeStatementLine($bankAccount, ['amount' => 100000])->id;
            $journal = $this->makeJournal($company, $period, ['debit_account' => $glAccount, 'credit_account' => $offsetAccount, 'amount' => 100000]);
            app(JournalService::class)->post($journal, $this->adminUserId());
            $journalLineId = $journal->fresh()->lines()->where('account_id', $glAccount->id)->value('id');
        });

        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/match", ['statement_line_id' => $lineAId, 'journal_line_id' => $journalLineId])->assertRedirect();
        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/match", ['statement_line_id' => $lineBId, 'journal_line_id' => $journalLineId])->assertSessionHasErrors(['journal_line']);
    }

    public function test_unmatch_ignore_and_unignore(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$bankAccountId, $matchedLineId, $unmatchedLineId] = [null, null, null];
        $tenant->run(function () use (&$bankAccountId, &$matchedLineId, &$unmatchedLineId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);
            $bankAccountId = $bankAccount->id;
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            $matchedLineId = $this->makeStatementLine($bankAccount, ['amount' => 100000])->id;
            $journal = $this->makeJournal($company, $period, ['debit_account' => $glAccount, 'credit_account' => $offsetAccount, 'amount' => 100000]);
            app(JournalService::class)->post($journal, $this->adminUserId());
            $journalLineId = $journal->fresh()->lines()->where('account_id', $glAccount->id)->value('id');
            app(BankReconciliationService::class)->match($bankAccount, BankStatementLine::find($matchedLineId), GlJournalLine::find($journalLineId), $this->adminUserId());

            $unmatchedLineId = $this->makeStatementLine($bankAccount, ['amount' => 5000])->id;
        });

        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/lines/{$matchedLineId}/unmatch")->assertRedirect();
        $tenant->run(function () use ($matchedLineId) {
            $this->assertSame(BankStatementLine::STATUS_UNMATCHED, BankStatementLine::query()->find($matchedLineId)->status);
        });

        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/lines/{$unmatchedLineId}/ignore")->assertRedirect();
        $tenant->run(function () use ($unmatchedLineId) {
            $this->assertSame(BankStatementLine::STATUS_IGNORED, BankStatementLine::query()->find($unmatchedLineId)->status);
        });

        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/lines/{$unmatchedLineId}/unignore")->assertRedirect();
        $tenant->run(function () use ($unmatchedLineId) {
            $this->assertSame(BankStatementLine::STATUS_UNMATCHED, BankStatementLine::query()->find($unmatchedLineId)->status);
        });
    }

    public function test_ignore_rejects_a_non_unmatched_line_and_line_actions_reject_a_line_from_another_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$bankAccountId, $otherBankAccountId, $matchedLineId, $otherAccountLineId] = [null, null, null, null];
        $tenant->run(function () use (&$bankAccountId, &$otherBankAccountId, &$matchedLineId, &$otherAccountLineId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);
            $bankAccountId = $bankAccount->id;
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            $matchedLineId = $this->makeStatementLine($bankAccount, ['amount' => 100000])->id;
            $journal = $this->makeJournal($company, $period, ['debit_account' => $glAccount, 'credit_account' => $offsetAccount, 'amount' => 100000]);
            app(JournalService::class)->post($journal, $this->adminUserId());
            $journalLineId = $journal->fresh()->lines()->where('account_id', $glAccount->id)->value('id');
            app(BankReconciliationService::class)->match($bankAccount, BankStatementLine::find($matchedLineId), GlJournalLine::find($journalLineId), $this->adminUserId());

            $otherGlAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $otherBankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Other', 'currency_code' => 'IDR', 'gl_account_id' => $otherGlAccount->id]);
            $otherBankAccountId = $otherBankAccount->id;
            $otherAccountLineId = $this->makeStatementLine($otherBankAccount, ['amount' => 1000])->id;
        });

        // Ignoring an already-matched line is rejected (only unmatched lines can be ignored).
        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/lines/{$matchedLineId}/ignore")->assertSessionHasErrors(['statement_line']);

        // A line that belongs to a different bank account is rejected regardless of action.
        $this->post("/accounting/bank-reconciliation/{$bankAccountId}/lines/{$otherAccountLineId}/ignore")->assertSessionHasErrors(['statement_line']);
    }

    public function test_auto_match_rejects_a_foreign_currency_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $bankAccountId = null;
        $tenant->run(function () use (&$bankAccountId) {
            $company = $this->makeCompany(['base_currency' => 'IDR']);
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01', 'rate_to_base' => 15000]);
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccountId = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'USD', 'currency_code' => 'USD', 'gl_account_id' => $glAccount->id])->id;
        });

        $tenant->run(function () use ($bankAccountId) {
            $bankAccount = BankAccount::query()->find($bankAccountId);
            $this->expectException(ValidationException::class);
            app(BankReconciliationService::class)->autoMatch($bankAccount, $this->adminUserId());
        });
    }
}
