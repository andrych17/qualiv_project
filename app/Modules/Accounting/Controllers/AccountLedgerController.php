<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\AccountLedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3N — Trial Balance's drill-down target: every posted line for one account, generalized
 * from BankAccountService::cashBook() via AccountLedgerService.
 *
 * §3J's Budget vs. Actual reuses this same controller/route/page for a second drill-down
 * shape rather than building a parallel one: passing `cost_center_id` alongside
 * `fiscal_period_id` switches to AccountLedgerService::forAccountAndPeriod() — a single
 * period, cost-center-scoped slice with a period total instead of a cumulative running
 * balance. `fiscal_period_id` alone keeps Trial Balance's original cumulative-through-date
 * behavior untouched.
 */
class AccountLedgerController extends Controller
{
    public function __construct(private readonly AccountLedgerService $service) {}

    public function show(Request $request, Account $account): Response
    {
        $periodId = $request->integer('fiscal_period_id');

        // `has()`, not `query() !== null`: Laravel's default ConvertEmptyStringsToNull
        // middleware turns an empty `cost_center_id=` (the "Unassigned" scope) into null
        // before this ever runs, which would otherwise be indistinguishable from the key
        // being absent entirely (Trial Balance's plain cumulative-mode link). The key's
        // PRESENCE is what selects period-scoped mode; its (possibly null) value is read
        // separately below to mean "Unassigned" vs. a specific cost center.
        if ($periodId && $request->has('cost_center_id')) {
            $period = FiscalPeriod::query()->findOrFail($periodId);
            $costCenterRaw = $request->query('cost_center_id');
            $costCenterId = $costCenterRaw !== null && $costCenterRaw !== '' ? (int) $costCenterRaw : null;
            $ledger = $this->service->forAccountAndPeriod($account, $period, $costCenterId);

            return Inertia::render('Accounting/Reports/AccountLedger', [
                'account' => ['id' => $account->id, 'company_id' => $account->company_id, 'account_code' => $account->account_code, 'account_name' => $account->account_name],
                'throughDate' => null,
                'periodLabel' => "Period {$period->period_no} ({$period->start_date->format('M Y')})",
                'lines' => $ledger['rows'],
                'closingBalance' => $ledger['periodTotal'],
                'closingBalanceLabel' => 'Period total',
                'back' => [
                    'href' => route('accounting.reports.budget-vs-actual', array_filter([
                        'company_id' => $request->query('company_id'),
                        'fiscal_year_id' => $request->query('fiscal_year_id'),
                        'cost_center_id' => $costCenterId,
                    ])),
                    'label' => 'Budget vs. Actual',
                ],
            ]);
        }

        $throughDate = $periodId ? FiscalPeriod::query()->findOrFail($periodId)->end_date->toDateString() : null;
        $ledger = $this->service->forAccount($account, $throughDate);

        return Inertia::render('Accounting/Reports/AccountLedger', [
            'account' => ['id' => $account->id, 'company_id' => $account->company_id, 'account_code' => $account->account_code, 'account_name' => $account->account_name],
            'throughDate' => $throughDate,
            'periodLabel' => null,
            'lines' => $ledger['rows'],
            'closingBalance' => $ledger['closingBalance'],
            'closingBalanceLabel' => 'Closing balance',
            'back' => ['href' => route('accounting.reports.trial-balance', ['company_id' => $account->company_id]), 'label' => 'Trial Balance'],
        ]);
    }
}
