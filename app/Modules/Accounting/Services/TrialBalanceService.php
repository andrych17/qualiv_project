<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;

/**
 * §3N — the classical trial balance: net debit or credit per account (not signed by normal
 * balance — an account's own normal_balance is irrelevant here, only the raw debit/credit
 * totals matter), through and including the selected period. Column totals matching is the
 * report's whole point — it's a live check that every posted journal actually balanced,
 * which JournalService::post() already enforces at post time, so this doubles as a regression
 * check on that guarantee.
 */
class TrialBalanceService
{
    public function __construct(private readonly AccountBalanceService $accountBalanceService) {}

    /** @return array{rows: list<array>, totalDebit: float, totalCredit: float} */
    public function generate(Company $company, FiscalPeriod $throughPeriod): array
    {
        $balances = $this->accountBalanceService->cumulativeBalances($company, $throughPeriod);

        $rows = $balances->map(function (array $row) {
            $net = round($row['debit'] - $row['credit'], 2);

            return [
                'account_id' => $row['account']->id,
                'account_code' => $row['account']->account_code,
                'account_name' => $row['account']->account_name,
                'debit' => $net > 0 ? $net : 0.0,
                'credit' => $net < 0 ? -$net : 0.0,
            ];
        })->filter(fn (array $r) => $r['debit'] !== 0.0 || $r['credit'] !== 0.0)->values()->all();

        return [
            'rows' => $rows,
            'totalDebit' => round(array_sum(array_column($rows, 'debit')), 2),
            'totalCredit' => round(array_sum(array_column($rows, 'credit')), 2),
        ];
    }
}
