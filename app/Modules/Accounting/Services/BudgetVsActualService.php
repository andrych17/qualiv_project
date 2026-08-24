<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use Illuminate\Support\Collection;

/**
 * §3J — Budget vs. Actual. Both figures share one sign convention (same normalization
 * AccountBalanceService/AllocationRunService already use elsewhere): positive = activity on
 * the account's own normal_balance side. A 5,000,000 expense budget means "5,000,000 of
 * debit-side activity," and the actual is computed identically from posted GL lines — so
 * variance has exactly one definition everywhere in this report: `actual - budget`.
 * Positive variance = overspend for an expense account, overperformance for a revenue
 * account; it is never inverted per row.
 *
 * variance_pct is null (never 0, INF, or a fabricated 100%) whenever budget is ~0 — an
 * unbudgeted-but-actual-nonzero cell is a real, common case, not an edge case, and a
 * percentage against a zero base has no meaning to render.
 */
class BudgetVsActualService
{
    /**
     * @return list<array{account_id:int, account_code:string, account_name:string, fiscal_period_id:int, period_no:int, budget:float, actual:float, variance:float, variance_pct:?float}>
     */
    public function report(Company $company, FiscalYear $fiscalYear, ?int $costCenterId): array
    {
        $budget = Budget::query()->where('company_id', $company->id)->where('fiscal_year_id', $fiscalYear->id)->first();
        $periods = FiscalPeriod::query()->where('fiscal_year_id', $fiscalYear->id)->orderBy('period_no')->get();
        $accounts = Account::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('account_code')->get();
        $accountIds = $accounts->pluck('id');

        $budgetLines = $budget
            ? BudgetLine::query()->where('budget_id', $budget->id)
                ->when($costCenterId === null, fn ($q) => $q->whereNull('cost_center_id'), fn ($q) => $q->where('cost_center_id', $costCenterId))
                ->get()
                ->keyBy(fn (BudgetLine $l) => "{$l->account_id}-{$l->fiscal_period_id}")
            : collect();

        $rows = [];
        foreach ($periods as $period) {
            $actuals = $this->actualsForPeriod($accountIds, $period, $costCenterId);

            foreach ($accounts as $account) {
                $budgetAmt = (float) ($budgetLines->get("{$account->id}-{$period->id}")->amount ?? 0.0);

                $t = $actuals->get($account->id);
                $debit = (float) ($t->total_debit ?? 0);
                $credit = (float) ($t->total_credit ?? 0);
                $actualAmt = round($account->normal_balance === Account::BALANCE_DEBIT ? $debit - $credit : $credit - $debit, 2);

                if (abs($budgetAmt) < 0.005 && abs($actualAmt) < 0.005) {
                    continue;
                }

                $variance = round($actualAmt - $budgetAmt, 2);

                $rows[] = [
                    'account_id' => $account->id,
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'fiscal_period_id' => $period->id,
                    'period_no' => $period->period_no,
                    'budget' => $budgetAmt,
                    'actual' => $actualAmt,
                    'variance' => $variance,
                    'variance_pct' => abs($budgetAmt) > 0.005 ? round($variance / abs($budgetAmt) * 100, 2) : null,
                ];
            }
        }

        return $rows;
    }

    /** @param  Collection<int, int>  $accountIds */
    private function actualsForPeriod(Collection $accountIds, FiscalPeriod $period, ?int $costCenterId): Collection
    {
        return GlJournalLine::query()
            ->whereIn('account_id', $accountIds)
            ->when($costCenterId === null, fn ($q) => $q->whereNull('cost_center_id'), fn ($q) => $q->where('cost_center_id', $costCenterId))
            ->whereHas('journal', fn ($q) => $q->where('status', GlJournal::STATUS_POSTED)->where('fiscal_period_id', $period->id))
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');
    }
}
