<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AllocationRule;
use App\Modules\Accounting\Models\AllocationRuleTarget;
use App\Modules\Accounting\Models\AllocationRun;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3I — runs an AllocationRule for one fiscal period: reads the source account/cost-center
 * pool's posted balance for that period, then posts ONE journal that debits each target
 * cost center and credits the source, all on the same account (source_account_id) — the
 * account's total balance never moves, only its cost-center attribution does. "Never
 * mutates the original entry" (§3I) is satisfied automatically: this only ever creates a
 * new journal, never touches the lines that produced the pooled balance.
 */
class AllocationRunService
{
    public function __construct(private readonly JournalService $journals) {}

    /** @return array{sourceAmount: float, lines: list<array{cost_center_id: int, amount: float}>} */
    public function preview(AllocationRule $rule, FiscalPeriod $period): array
    {
        $sourceAmount = $this->sourceAmountFor($rule, $period);

        return ['sourceAmount' => $sourceAmount, 'lines' => $this->splitAmount($sourceAmount, $rule->targets)];
    }

    public function run(AllocationRule $rule, FiscalPeriod $period, int $userId): AllocationRun
    {
        return DB::transaction(function () use ($rule, $period, $userId) {
            $lockedRule = AllocationRule::query()->lockForUpdate()->findOrFail($rule->id);

            if (AllocationRun::query()->where('allocation_rule_id', $lockedRule->id)->where('fiscal_period_id', $period->id)->exists()) {
                throw ValidationException::withMessages(['fiscal_period_id' => 'This rule has already been run for this period.']);
            }

            $sourceAmount = $this->sourceAmountFor($lockedRule, $period);
            if (abs($sourceAmount) < 0.005) {
                throw ValidationException::withMessages(['fiscal_period_id' => 'Nothing posted to the source account/cost center in this period — nothing to allocate.']);
            }

            $targetLines = array_filter($this->splitAmount($sourceAmount, $lockedRule->targets), fn ($l) => abs($l['amount']) > 0.005);

            $lines = array_map(fn ($l) => [
                'account_id' => $lockedRule->source_account_id,
                'cost_center_id' => $l['cost_center_id'],
                'debit' => $l['amount'] > 0 ? $l['amount'] : 0,
                'credit' => $l['amount'] < 0 ? -$l['amount'] : 0,
                'description' => "Allocation — {$lockedRule->name}",
            ], $targetLines);

            $lines[] = [
                'account_id' => $lockedRule->source_account_id,
                'cost_center_id' => $lockedRule->source_cost_center_id,
                'debit' => $sourceAmount < 0 ? -$sourceAmount : 0,
                'credit' => $sourceAmount > 0 ? $sourceAmount : 0,
                'description' => "Allocation — {$lockedRule->name}",
            ];

            $journal = $this->journals->create([
                'company_id' => $lockedRule->company_id,
                'fiscal_period_id' => $period->id,
                'journal_date' => $period->end_date->toDateString(),
                'currency_code' => $lockedRule->company->base_currency,
                'memo' => "Allocation — {$lockedRule->name} (period {$period->period_no})",
                'subject_type' => 'accounting.allocation_rules',
                'subject_id' => (string) $lockedRule->id,
            ], $lines, $userId, 'allocation');

            $journal = $this->journals->post($journal, $userId);

            return AllocationRun::query()->create([
                'allocation_rule_id' => $lockedRule->id,
                'fiscal_period_id' => $period->id,
                'source_amount' => $sourceAmount,
                'journal_id' => $journal->id,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Positive = the pool sits on the account's normal-balance side (the common case — an
     * expense account with a debit pool). Sign-symmetric either way: the journal built from
     * it always balances.
     *
     * Always re-fetches the account rather than trusting $rule->sourceAccount — a caller that
     * eager-loads the relation with a partial column select (e.g. for a display-only label)
     * would silently leave normal_balance null here, which compares false against
     * Account::BALANCE_DEBIT and flips this whole calculation's sign with no error anywhere.
     * Found exactly that way in AllocationRunController's browser walkthrough.
     */
    private function sourceAmountFor(AllocationRule $rule, FiscalPeriod $period): float
    {
        $account = Account::query()->findOrFail($rule->source_account_id);

        $sums = GlJournalLine::query()
            ->where('account_id', $rule->source_account_id)
            ->when(
                $rule->source_cost_center_id === null,
                fn ($q) => $q->whereNull('cost_center_id'),
                fn ($q) => $q->where('cost_center_id', $rule->source_cost_center_id),
            )
            ->whereHas('journal', fn ($q) => $q->where('status', GlJournal::STATUS_POSTED)->where('fiscal_period_id', $period->id))
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        $debit = (float) $sums->total_debit;
        $credit = (float) $sums->total_credit;

        return round($account->normal_balance === Account::BALANCE_DEBIT ? $debit - $credit : $credit - $debit, 2);
    }

    /**
     * Rounding-remainder-to-last-target split, so the lines always sum exactly to $total —
     * same discipline ArInvoiceService's AR-control-debit-is-the-sum-of-credits comment
     * describes for a different reason (never let independent per-line rounding drift the
     * total by a cent).
     *
     * @param  Collection<int, AllocationRuleTarget>  $targets
     * @return list<array{cost_center_id: int, amount: float}>
     */
    private function splitAmount(float $total, $targets): array
    {
        $lines = [];
        $allocated = 0.0;
        $count = $targets->count();

        foreach ($targets as $i => $target) {
            $amount = $i === $count - 1
                ? round($total - $allocated, 2)
                : round($total * (float) $target->percentage / 100, 2);
            $allocated += $amount;
            $lines[] = ['cost_center_id' => $target->cost_center_id, 'amount' => $amount];
        }

        return $lines;
    }
}
