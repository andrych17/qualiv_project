<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;

/** §3N — P&L (Laporan Laba Rugi), single-period activity (not cumulative — see AccountBalanceService::periodBalances()), current period vs. the immediately preceding one. */
class ProfitLossService
{
    public function __construct(private readonly AccountBalanceService $accountBalanceService) {}

    /** @return array{current: array, prior: ?array} */
    public function generate(Company $company, FiscalPeriod $period): array
    {
        return [
            'current' => $this->forPeriod($company, $period),
            'prior' => ($prior = $this->priorPeriod($company, $period)) ? $this->forPeriod($company, $prior) : null,
        ];
    }

    /** @return array{periodLabel: string, revenue: list<array>, cogs: list<array>, expense: list<array>, totalRevenue: float, totalCogs: float, grossProfit: float, totalExpense: float, netIncome: float} */
    private function forPeriod(Company $company, FiscalPeriod $period): array
    {
        $balances = $this->accountBalanceService->periodBalances($company, $period);

        $section = fn (string $type) => $balances->filter(fn ($r) => $r['account']->account_type === $type && $r['balance'] !== 0.0)
            ->map(fn ($r) => ['account_id' => $r['account']->id, 'account_code' => $r['account']->account_code, 'account_name' => $r['account']->account_name, 'balance' => $r['balance']])
            ->values()->all();

        $revenue = $section(Account::TYPE_REVENUE);
        $cogs = $section(Account::TYPE_COGS);
        $expense = $section(Account::TYPE_EXPENSE);

        $totalRevenue = round(array_sum(array_column($revenue, 'balance')), 2);
        $totalCogs = round(array_sum(array_column($cogs, 'balance')), 2);
        $totalExpense = round(array_sum(array_column($expense, 'balance')), 2);
        $grossProfit = round($totalRevenue - $totalCogs, 2);

        return [
            'periodLabel' => "Period {$period->period_no}",
            'periodEnd' => $period->end_date->toDateString(),
            'revenue' => $revenue,
            'cogs' => $cogs,
            'expense' => $expense,
            'totalRevenue' => $totalRevenue,
            'totalCogs' => $totalCogs,
            'grossProfit' => $grossProfit,
            'totalExpense' => $totalExpense,
            'netIncome' => round($grossProfit - $totalExpense, 2),
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
