<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;

/**
 * §3N — Balance Sheet (Neraca), grouped by account_type (Aset/Liabilitas/Ekuitas) — a
 * deliberately flat v1 grouping. Full PSAK presentation further splits each into
 * current/non-current; the Account model carries no such classification yet, and adding one
 * is its own scope (a new column, migration, and form field) beyond what this report needs
 * to be correct and usable today. Documented here rather than silently simplified.
 *
 * This system never posts a period-close/roll-forward entry to Retained Earnings (confirmed:
 * nothing in the codebase writes to it), so the balance sheet would never actually balance
 * without a synthetic "current earnings" line — cumulative Revenue − COGS − Expense through
 * the as-of period — appended to Equity. That line, plus the totalAssets vs
 * totalLiabilitiesAndEquity check, is the report's own self-verification: if `variance` isn't
 * ~0, something posted outside double-entry (which JournalService should have already
 * prevented) or a §3N filter went wrong.
 */
class BalanceSheetService
{
    public function __construct(private readonly AccountBalanceService $accountBalanceService) {}

    /** @return array{current: array, prior: ?array} */
    public function generate(Company $company, FiscalPeriod $asOfPeriod): array
    {
        return [
            'current' => $this->asOf($company, $asOfPeriod),
            'prior' => ($prior = $this->priorPeriod($company, $asOfPeriod)) ? $this->asOf($company, $prior) : null,
        ];
    }

    /** @return array{periodLabel: string, asOfDate: string, assets: list<array>, liabilities: list<array>, equity: list<array>, totalAssets: float, totalLiabilitiesAndEquity: float, variance: float} */
    private function asOf(Company $company, FiscalPeriod $period): array
    {
        $balances = $this->accountBalanceService->cumulativeBalances($company, $period);

        // A contra-asset (e.g. Accumulated Depreciation: type=asset but normal_balance=credit,
        // per AccountService's starter COA) has a POSITIVE `balance` by AccountBalanceService's
        // own convention (that's what "credit-normal, credit exceeds debit" means) — but it
        // must DEDUCT from total assets, not add to them, or the sheet is off by exactly its
        // own balance. Flip the sign for any asset-type account that is itself credit-normal;
        // liabilities/equity need no such flip since every account in those types is already
        // credit-normal by construction, matching its section's own expected sign.
        $section = fn (string $type) => $balances->filter(fn ($r) => $r['account']->account_type === $type && $r['balance'] !== 0.0)
            ->map(function ($r) use ($type) {
                $isContra = $type === Account::TYPE_ASSET && $r['account']->normal_balance === Account::BALANCE_CREDIT;

                return ['account_id' => $r['account']->id, 'account_code' => $r['account']->account_code, 'account_name' => $r['account']->account_name, 'balance' => $isContra ? -$r['balance'] : $r['balance']];
            })
            ->values()->all();

        $assets = $section(Account::TYPE_ASSET);
        $liabilities = $section(Account::TYPE_LIABILITY);
        $equity = $section(Account::TYPE_EQUITY);

        $currentEarnings = round(
            $balances->where('account.account_type', Account::TYPE_REVENUE)->sum('balance')
            - $balances->whereIn('account.account_type', [Account::TYPE_COGS, Account::TYPE_EXPENSE])->sum('balance'),
            2
        );
        if ($currentEarnings !== 0.0) {
            $equity[] = ['account_id' => null, 'account_code' => null, 'account_name' => 'Laba (Rugi) Tahun Berjalan — Current Earnings', 'balance' => $currentEarnings];
        }

        $totalAssets = round(array_sum(array_column($assets, 'balance')), 2);
        $totalLiabilitiesAndEquity = round(array_sum(array_column($liabilities, 'balance')) + array_sum(array_column($equity, 'balance')), 2);

        return [
            'periodLabel' => "Period {$period->period_no}",
            'asOfDate' => $period->end_date->toDateString(),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totalAssets' => $totalAssets,
            'totalLiabilitiesAndEquity' => $totalLiabilitiesAndEquity,
            'variance' => round($totalAssets - $totalLiabilitiesAndEquity, 2),
        ];
    }

    private function priorPeriod(Company $company, FiscalPeriod $period): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->where('start_date', '<', $period->start_date)
            ->orderByDesc('start_date')
            ->first();
    }
}
