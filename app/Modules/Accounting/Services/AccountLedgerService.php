<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use Illuminate\Support\Collection;

/**
 * §3N — the GL-derived ledger for any single account, generalized from
 * BankAccountService::cashBook() (which now delegates here) so §3N's Trial Balance
 * drill-down and the cash book stay byte-identical instead of two slightly different
 * queries that could quietly disagree about what the books say.
 */
class AccountLedgerService
{
    /**
     * @param  string|null  $throughDate  inclusive upper bound on journal_date; null = all posted activity
     * @return array{rows: Collection, closingBalance: float}
     */
    public function forAccount(Account $account, ?string $throughDate = null): array
    {
        $lines = GlJournalLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journal', fn ($q) => $q->where('status', GlJournal::STATUS_POSTED)->when($throughDate, fn ($q2) => $q2->whereDate('journal_date', '<=', $throughDate)))
            ->with('journal:id,journal_date,memo,source,subject_type,subject_id,status')
            ->get()
            ->sort(fn (GlJournalLine $a, GlJournalLine $b) => [$a->journal->journal_date->timestamp, $a->id] <=> [$b->journal->journal_date->timestamp, $b->id])
            ->values();

        $isDebitNormal = $account->normal_balance === Account::BALANCE_DEBIT;
        $running = 0.0;
        $rows = $lines->map(function (GlJournalLine $line) use (&$running, $isDebitNormal) {
            $delta = $isDebitNormal
                ? (float) $line->debit - (float) $line->credit
                : (float) $line->credit - (float) $line->debit;
            $running += $delta;

            return [
                'journal_line_id' => $line->id,
                'journal_id' => $line->journal_id,
                'date' => $line->journal->journal_date->toDateString(),
                'memo' => $line->description ?? $line->journal->memo,
                'source' => $line->journal->source,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'delta' => round($delta, 2),
                'running_balance' => round($running, 2),
            ];
        });

        return ['rows' => $rows, 'closingBalance' => round($running, 2)];
    }

    /**
     * §3J Budget vs. Actual's drill-down: every posted line for one account, ONE period,
     * ONE cost-center scope (including the null "Unassigned" scope — matched exactly, not
     * "any cost center"). Deliberately returns a period total instead of a running balance —
     * forAccount()'s running balance is a cumulative-since-inception figure, and seeding one
     * at zero mid-year for a single period would look authoritative while being wrong.
     *
     * @return array{rows: Collection, periodTotal: float}
     */
    public function forAccountAndPeriod(Account $account, FiscalPeriod $period, ?int $costCenterId): array
    {
        $lines = GlJournalLine::query()
            ->where('account_id', $account->id)
            ->when($costCenterId === null, fn ($q) => $q->whereNull('cost_center_id'), fn ($q) => $q->where('cost_center_id', $costCenterId))
            ->whereHas('journal', fn ($q) => $q->where('status', GlJournal::STATUS_POSTED)->where('fiscal_period_id', $period->id))
            ->with('journal:id,journal_date,memo,source,subject_type,subject_id,status')
            ->get()
            ->sort(fn (GlJournalLine $a, GlJournalLine $b) => [$a->journal->journal_date->timestamp, $a->id] <=> [$b->journal->journal_date->timestamp, $b->id])
            ->values();

        $isDebitNormal = $account->normal_balance === Account::BALANCE_DEBIT;
        $rows = $lines->map(fn (GlJournalLine $line) => [
            'journal_line_id' => $line->id,
            'journal_id' => $line->journal_id,
            'date' => $line->journal->journal_date->toDateString(),
            'memo' => $line->description ?? $line->journal->memo,
            'source' => $line->journal->source,
            'debit' => (float) $line->debit,
            'credit' => (float) $line->credit,
        ]);

        $periodTotal = round($isDebitNormal ? $lines->sum('debit') - $lines->sum('credit') : $lines->sum('credit') - $lines->sum('debit'), 2);

        return ['rows' => $rows, 'periodTotal' => $periodTotal];
    }
}
