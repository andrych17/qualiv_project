<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3Q bank reconciliation. v1 is base-currency accounts only — a foreign-currency bank
 * account's statement lines are in that currency while gl_journal_lines carries base-currency
 * amounts, so amount comparison is meaningless across the two; BankReconciliationController
 * rejects non-base-currency accounts rather than showing a workspace that can never match.
 *
 * A statement line's signed amount (+inflow/-outflow) is compared against a journal line's
 * delta using the SAME sign convention as BankAccountService::cashBook() (debit-normal account:
 * debit-credit; credit-normal: credit-debit) — the two must never diverge or this reconciliation
 * and the cash book would silently disagree about what "book balance" means.
 */
class BankReconciliationService
{
    /** Auto-match only commits an exact-amount, in-window, UNAMBIGUOUS pairing — see matchCandidates(). A wrong auto-match that gets marked reconciled is worse than a missed one. */
    private const DATE_WINDOW_DAYS = 3;

    public function unmatchedStatementLines(BankAccount $bankAccount): Collection
    {
        return BankStatementLine::query()
            ->whereHas('import', fn ($q) => $q->where('bank_account_id', $bankAccount->id))
            ->where('status', BankStatementLine::STATUS_UNMATCHED)
            ->orderBy('line_date')
            ->get();
    }

    public function ignoredStatementLines(BankAccount $bankAccount): Collection
    {
        return BankStatementLine::query()
            ->whereHas('import', fn ($q) => $q->where('bank_account_id', $bankAccount->id))
            ->where('status', BankStatementLine::STATUS_IGNORED)
            ->orderBy('line_date')
            ->get();
    }

    public function matchedStatementLines(BankAccount $bankAccount): Collection
    {
        return BankStatementLine::query()
            ->whereHas('import', fn ($q) => $q->where('bank_account_id', $bankAccount->id))
            ->where('status', BankStatementLine::STATUS_MATCHED)
            ->with('matchedJournalLine.journal:id,journal_date,memo')
            ->orderBy('line_date')
            ->get();
    }

    /** Posted journal lines on this account's GL account that no statement line has claimed yet. */
    public function unmatchedJournalLines(BankAccount $bankAccount): Collection
    {
        $claimed = BankStatementLine::query()->whereNotNull('matched_journal_line_id')->pluck('matched_journal_line_id');

        return GlJournalLine::query()
            ->where('account_id', $bankAccount->gl_account_id)
            ->whereHas('journal', fn ($q) => $q->where('status', GlJournal::STATUS_POSTED))
            ->whereNotIn('id', $claimed)
            ->with('journal:id,journal_date,memo,source,status')
            ->get()
            ->sort(fn (GlJournalLine $a, GlJournalLine $b) => $a->journal->journal_date->timestamp <=> $b->journal->journal_date->timestamp)
            ->values();
    }

    private function journalLineDelta(GlJournalLine $line, bool $isDebitNormal): float
    {
        $delta = $isDebitNormal
            ? (float) $line->debit - (float) $line->credit
            : (float) $line->credit - (float) $line->debit;

        return round($delta, 2);
    }

    /**
     * Exact amount + date-window candidates for one statement line. Reference-string
     * similarity is deliberately not scored here — bank memo text is too noisy to build
     * confidence from; it can only ever be a human's own tiebreaker when matching manually.
     */
    public function matchCandidates(BankAccount $bankAccount, BankStatementLine $line): Collection
    {
        $isDebitNormal = $bankAccount->glAccount->normal_balance === Account::BALANCE_DEBIT;

        return $this->unmatchedJournalLines($bankAccount)->filter(function (GlJournalLine $jl) use ($line, $isDebitNormal) {
            if ($this->journalLineDelta($jl, $isDebitNormal) !== round((float) $line->amount, 2)) {
                return false;
            }

            return abs($jl->journal->journal_date->diffInDays($line->line_date)) <= self::DATE_WINDOW_DAYS;
        })->values();
    }

    /** @return int number of statement lines matched */
    public function autoMatch(BankAccount $bankAccount, int $userId): int
    {
        if ($bankAccount->currency_code !== $bankAccount->company->base_currency) {
            throw ValidationException::withMessages(['bank_account' => 'Reconciliation is only available for base-currency bank accounts.']);
        }

        return DB::transaction(function () use ($bankAccount, $userId) {
            $matched = 0;

            foreach ($this->unmatchedStatementLines($bankAccount) as $line) {
                $candidates = $this->matchCandidates($bankAccount, $line);

                if ($candidates->count() === 1) {
                    $this->match($bankAccount, $line, $candidates->first(), $userId);
                    $matched++;
                }
                // 0 or >1 candidates: left for manual matching — see class docblock.
            }

            return $matched;
        });
    }

    /**
     * Amount equality is enforced here too, not just for auto-match — a "match" between
     * unequal amounts isn't a match, it's a reconciling adjustment that belongs in the GL as
     * its own entry. Enforcing this keeps worksheet()'s variance meaningful: every matched
     * pair contributes equally to both sides, so variance is structurally always zero once
     * everything is either matched or left as a visible outstanding/uncleared item.
     */
    public function match(BankAccount $bankAccount, BankStatementLine $line, GlJournalLine $journalLine, int $userId): void
    {
        if ($line->status !== BankStatementLine::STATUS_UNMATCHED) {
            throw ValidationException::withMessages(['statement_line' => 'This statement line is not unmatched.']);
        }

        if ($journalLine->account_id !== $bankAccount->gl_account_id) {
            throw ValidationException::withMessages(['journal_line' => 'That journal line does not belong to this bank account.']);
        }

        if ($journalLine->journal->status !== GlJournal::STATUS_POSTED) {
            throw ValidationException::withMessages(['journal_line' => 'That journal is not posted.']);
        }

        if (BankStatementLine::query()->where('matched_journal_line_id', $journalLine->id)->exists()) {
            throw ValidationException::withMessages(['journal_line' => 'That journal line is already matched to another statement line.']);
        }

        $isDebitNormal = $bankAccount->glAccount->normal_balance === Account::BALANCE_DEBIT;
        if ($this->journalLineDelta($journalLine, $isDebitNormal) !== round((float) $line->amount, 2)) {
            throw ValidationException::withMessages(['journal_line' => 'Amounts do not match — book an adjustment entry instead of forcing a match.']);
        }

        $line->update([
            'status' => BankStatementLine::STATUS_MATCHED,
            'matched_journal_line_id' => $journalLine->id,
            'matched_at' => now(),
            'matched_by' => $userId,
        ]);
    }

    public function unmatch(BankStatementLine $line): void
    {
        $line->update([
            'status' => BankStatementLine::STATUS_UNMATCHED,
            'matched_journal_line_id' => null,
            'matched_at' => null,
            'matched_by' => null,
        ]);
    }

    public function ignore(BankStatementLine $line): void
    {
        if ($line->status !== BankStatementLine::STATUS_UNMATCHED) {
            throw ValidationException::withMessages(['statement_line' => 'Only unmatched lines can be ignored.']);
        }

        $line->update(['status' => BankStatementLine::STATUS_IGNORED]);
    }

    public function unignore(BankStatementLine $line): void
    {
        $line->update(['status' => BankStatementLine::STATUS_UNMATCHED]);
    }

    /**
     * The classic worksheet: book balance less outstanding (unmatched) book-side items equals
     * the adjusted book balance; matched statement total is the adjusted statement-side figure.
     * Because match() enforces exact-amount equality per pair, the two adjusted figures are
     * structurally equal once every remaining discrepancy is visible as an outstanding or
     * uncleared item count — variance is a safety check, not expected to ever read nonzero.
     * Book balance is BankAccountService::cashBook()'s own number, not re-derived here.
     */
    public function worksheet(BankAccount $bankAccount, float $bookClosingBalance): array
    {
        $unmatchedJournalLines = $this->unmatchedJournalLines($bankAccount);
        $isDebitNormal = $bankAccount->glAccount->normal_balance === Account::BALANCE_DEBIT;
        $outstandingBookTotal = round($unmatchedJournalLines->sum(fn (GlJournalLine $jl) => $this->journalLineDelta($jl, $isDebitNormal)), 2);
        $adjustedBookBalance = round($bookClosingBalance - $outstandingBookTotal, 2);

        $unmatchedStatementLines = $this->unmatchedStatementLines($bankAccount);
        $unclearedStatementTotal = round((float) $unmatchedStatementLines->sum('amount'), 2);
        $matchedStatementTotal = round((float) $this->matchedStatementLines($bankAccount)->sum('amount'), 2);

        return [
            'bookClosingBalance' => round($bookClosingBalance, 2),
            'outstandingBookItems' => $unmatchedJournalLines->count(),
            'outstandingBookTotal' => $outstandingBookTotal,
            'adjustedBookBalance' => $adjustedBookBalance,
            'matchedStatementTotal' => $matchedStatementTotal,
            'unclearedStatementItems' => $unmatchedStatementLines->count(),
            'unclearedStatementTotal' => $unclearedStatementTotal,
            'adjustedStatementBalance' => $matchedStatementTotal,
            'variance' => round($adjustedBookBalance - $matchedStatementTotal, 2),
        ];
    }
}
