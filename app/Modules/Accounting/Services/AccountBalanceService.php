<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use Illuminate\Support\Collection;

/**
 * §3N — the shared computational primitive behind Trial Balance, Balance Sheet, P&L, and
 * Cash Flow: every one of them is "sum posted debit/credit per account over some set of
 * fiscal periods," they just differ in which periods and which accounts they present.
 *
 * Filters by `fiscal_period_id`, not `journal_date` — the fiscal period is what
 * JournalService::assertPeriodOpen() actually gates on, so it's the one consistent notion
 * of "which period a journal belongs to" across every report. A journal's own journal_date
 * is never validated to fall inside its fiscal_period_id's date range, so using journal_date
 * here instead would let Balance Sheet and P&L quietly disagree about the same journal.
 *
 * Every report generated from posted-only data (§3N rule) — GlJournal::STATUS_POSTED is the
 * only status these queries ever touch.
 */
class AccountBalanceService
{
    /**
     * Signed balance (by each account's own normal_balance) for every active account,
     * cumulative through and including $throughPeriod — the "as of" figure Trial Balance and
     * Balance Sheet both need.
     *
     * @return Collection<int, array{account: Account, debit: float, credit: float, balance: float}>
     */
    public function cumulativeBalances(Company $company, FiscalPeriod $throughPeriod): Collection
    {
        $periodIds = FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->where('start_date', '<=', $throughPeriod->start_date)
            ->pluck('id');

        return $this->balancesForPeriodIds($company, $periodIds);
    }

    /**
     * Signed balance for every active account, activity within $period only (not
     * cumulative) — what P&L needs, since revenue/expense are period figures, not
     * running totals (this system never posts a period-close/roll-forward entry).
     *
     * @return Collection<int, array{account: Account, debit: float, credit: float, balance: float}>
     */
    public function periodBalances(Company $company, FiscalPeriod $period): Collection
    {
        return $this->balancesForPeriodIds($company, collect([$period->id]));
    }

    /** @return Collection<int, array{account: Account, debit: float, credit: float, balance: float}> */
    private function balancesForPeriodIds(Company $company, Collection $periodIds): Collection
    {
        $accounts = Account::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('account_code')->get();

        $totals = GlJournalLine::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->whereHas('journal', fn ($q) => $q->where('status', GlJournal::STATUS_POSTED)->whereIn('fiscal_period_id', $periodIds))
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        return $accounts->map(function (Account $account) use ($totals) {
            $t = $totals->get($account->id);
            $debit = (float) ($t->total_debit ?? 0);
            $credit = (float) ($t->total_credit ?? 0);
            $balance = $account->normal_balance === Account::BALANCE_DEBIT ? $debit - $credit : $credit - $debit;

            return ['account' => $account, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'balance' => round($balance, 2)];
        })->keyBy(fn ($row) => $row['account']->id);
    }
}
