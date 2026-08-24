<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AssetDisposal;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use Illuminate\Support\Collection;

/**
 * §3N — indirect-method Cash Flow Statement, derived entirely from Balance Sheet movement +
 * P&L for the period (§3N rule: "no separate cash-flow data entry"). Classification is
 * mechanical, not manually tagged per account:
 *   - Investing = accounts referenced by §3G's fa_assets.asset_gl_account_id (fixed assets),
 *     using each period's actual disposal proceeds (not the gross asset-account delta).
 *   - Financing = equity accounts, excluding Retained Earnings (nothing posts to it — see
 *     BalanceSheetService docblock; excluded anyway as a safeguard, not load-bearing today).
 *   - Operating = Net Income, plus depreciation add-back, plus every other balance-sheet
 *     account's movement (AR, AP, inventory, tax payable, ...) — the standard indirect-method
 *     sweep.
 *   - Cash/bank accounts (BankAccount.gl_account_id) never appear as a line item — their
 *     combined movement IS the total this statement is computing, so it's the tie-out check,
 *     not an input.
 *
 * A disposal is the one place naive delta-tracking double-counts: the disposal journal
 * credits the asset account for its full cost and debits accumulated depreciation for its
 * full accumulated balance, neither of which is a new-period cash event. Both the asset-
 * account delta and the accumulated-depreciation delta EXCLUDE lines belonging to
 * AssetDisposal.journal_id — disposals contribute to this statement only via their actual
 * cash proceeds (investing) and a reversal of their P&L gain/loss (operating, since the cash
 * effect is already counted as proceeds, not through Net Income).
 *
 * `variance` (computed net change vs. the period's actual cash-account movement) must be
 * ~0 — if it isn't, some account's movement went unclassified.
 */
class CashFlowService
{
    public function __construct(
        private readonly AccountBalanceService $accountBalanceService,
        private readonly ProfitLossService $profitLossService,
    ) {}

    public function generate(Company $company, FiscalPeriod $period): array
    {
        $netIncome = $this->profitLossService->generate($company, $period)['current']['netIncome'];

        $assetAccountIds = FixedAsset::query()->where('company_id', $company->id)->pluck('asset_gl_account_id')->unique()->values();
        $accumDepAccountIds = FixedAsset::query()->where('company_id', $company->id)->pluck('accumulated_depreciation_gl_account_id')->unique()->values();
        $cashAccountIds = BankAccount::query()->where('company_id', $company->id)->pluck('gl_account_id')->unique()->values();

        $disposals = AssetDisposal::query()
            ->whereHas('asset', fn ($q) => $q->where('company_id', $company->id))
            ->whereDate('disposal_date', '>=', $period->start_date)
            ->whereDate('disposal_date', '<=', $period->end_date)
            ->get();
        $disposalJournalIds = $disposals->pluck('journal_id')->filter()->values();
        $disposalProceeds = round((float) $disposals->sum('proceeds'), 2);
        $disposalGainLossReversal = round(-(float) $disposals->sum('gain_loss_amount'), 2);

        $depreciationAddBack = $this->periodDeltaExcludingJournals($accumDepAccountIds, $period, $disposalJournalIds);
        $assetAdditions = $this->periodDeltaExcludingJournals($assetAccountIds, $period, $disposalJournalIds);

        $periodBalances = $this->accountBalanceService->periodBalances($company, $period);
        $excludedIds = $cashAccountIds->merge($assetAccountIds)->merge($accumDepAccountIds);

        $operatingOther = round($periodBalances
            ->reject(fn (array $r) => $excludedIds->contains($r['account']->id))
            ->reject(fn (array $r) => in_array($r['account']->account_type, [Account::TYPE_EQUITY, Account::TYPE_REVENUE, Account::TYPE_COGS, Account::TYPE_EXPENSE]))
            ->sum(fn (array $r) => $r['account']->account_type === Account::TYPE_ASSET ? -$r['balance'] : $r['balance']), 2);

        $financing = round($periodBalances
            ->filter(fn (array $r) => $r['account']->account_type === Account::TYPE_EQUITY && $r['account']->account_code !== '32000')
            ->sum('balance'), 2);

        $operatingTotal = round($netIncome + $depreciationAddBack + $disposalGainLossReversal + $operatingOther, 2);
        $investingTotal = round($disposalProceeds - $assetAdditions, 2);
        $financingTotal = $financing;
        $netChange = round($operatingTotal + $investingTotal + $financingTotal, 2);

        $actualCashChange = round($periodBalances->filter(fn (array $r) => $cashAccountIds->contains($r['account']->id))->sum('balance'), 2);

        return [
            'periodLabel' => "Period {$period->period_no}",
            'periodEnd' => $period->end_date->toDateString(),
            'netIncome' => $netIncome,
            'depreciationAddBack' => $depreciationAddBack,
            'disposalGainLossReversal' => $disposalGainLossReversal,
            'operatingOther' => $operatingOther,
            'operatingTotal' => $operatingTotal,
            'disposalProceeds' => $disposalProceeds,
            'assetAdditions' => $assetAdditions,
            'investingTotal' => $investingTotal,
            'financingTotal' => $financingTotal,
            'netChange' => $netChange,
            'actualCashChange' => $actualCashChange,
            'variance' => round($netChange - $actualCashChange, 2),
        ];
    }

    /** Δ for a set of accounts within one period, EXCLUDING specific journals (a disposal's own lines) — signed by each account's own normal_balance, same convention as AccountBalanceService. */
    private function periodDeltaExcludingJournals(Collection $accountIds, FiscalPeriod $period, Collection $excludeJournalIds): float
    {
        if ($accountIds->isEmpty()) {
            return 0.0;
        }

        $normalBalances = Account::query()->whereIn('id', $accountIds)->pluck('normal_balance', 'id');

        $totals = GlJournalLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('journal', fn ($q) => $q->where('status', GlJournal::STATUS_POSTED)->where('fiscal_period_id', $period->id))
            ->when($excludeJournalIds->isNotEmpty(), fn ($q) => $q->whereNotIn('journal_id', $excludeJournalIds))
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->groupBy('account_id')
            ->get();

        return round($totals->sum(function ($t) use ($normalBalances) {
            $isDebitNormal = $normalBalances->get($t->account_id) === Account::BALANCE_DEBIT;

            return $isDebitNormal ? ((float) $t->total_debit - (float) $t->total_credit) : ((float) $t->total_credit - (float) $t->total_debit);
        }), 2);
    }
}
