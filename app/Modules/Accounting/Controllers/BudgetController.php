<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Requests\ImportBudgetCsvRequest;
use App\Modules\Accounting\Requests\StoreBudgetGridRequest;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3J — the budget grid: one flat annual Budget per company/fiscal year, edited one cost
 * center scope at a time. getOrCreate() means there is always a Budget row to save against
 * once a company+fiscal year is selected, even before the user has entered a single figure.
 */
class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);
        $company = Company::query()->findOrFail($companyId);

        $fiscalYears = FiscalYear::query()->where('company_id', $companyId)->orderByDesc('year')->get(['id', 'year']);
        $fiscalYearId = $request->integer('fiscal_year_id') ?: $fiscalYears->first()?->id;

        $costCenters = CostCenter::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $costCenterId = $request->filled('cost_center_id') ? $request->integer('cost_center_id') : null;

        $grid = null;
        $budgetId = null;
        if ($fiscalYearId) {
            $fiscalYear = FiscalYear::query()->findOrFail($fiscalYearId);
            $budget = $this->service->getOrCreate($company, $fiscalYear, $request->user()->id);
            $budgetId = $budget->id;
            $grid = $this->buildGrid($budget, $costCenterId);
        }

        return Inertia::render('Accounting/Budgets/Grid', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'fiscalYears' => $fiscalYears->map(fn (FiscalYear $y) => ['value' => $y->id, 'label' => (string) $y->year]),
            'selectedFiscalYearId' => $fiscalYearId,
            'costCenters' => $costCenters->map(fn (CostCenter $c) => ['value' => $c->id, 'label' => "{$c->code} {$c->name}"]),
            'selectedCostCenterId' => $costCenterId,
            'budgetId' => $budgetId,
            'grid' => $grid,
        ]);
    }

    public function saveGrid(StoreBudgetGridRequest $request, Budget $budget)
    {
        $data = $request->validated();

        $this->service->saveGrid(
            $budget,
            $data['cost_center_id'] ?? null,
            array_map(fn (array $c) => ['account_id' => $c['account_id'], 'fiscal_period_id' => $c['fiscal_period_id'], 'amount' => (float) $c['amount']], $data['cells']),
            $request->user()->id,
        );

        return back()->with('success', 'Budget saved.');
    }

    public function importCsv(ImportBudgetCsvRequest $request, Budget $budget)
    {
        try {
            $result = $this->service->importCsv($budget, $request->file('file'), $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Imported {$result['imported']} budget line(s).");
    }

    /** @return array{periods: list<array{fiscal_period_id:int, period_no:int}>, accounts: list<array{account_id:int, account_code:string, account_name:string, cells: list<array{fiscal_period_id:int, amount: ?float}>}>} */
    private function buildGrid(Budget $budget, ?int $costCenterId): array
    {
        $periods = $budget->fiscalYear->periods()->orderBy('period_no')->get(['id', 'period_no']);
        $accounts = Account::query()->where('company_id', $budget->company_id)->where('is_active', true)->orderBy('account_code')->get();

        $existing = BudgetLine::query()->where('budget_id', $budget->id)
            ->when($costCenterId === null, fn ($q) => $q->whereNull('cost_center_id'), fn ($q) => $q->where('cost_center_id', $costCenterId))
            ->get()
            ->keyBy(fn (BudgetLine $l) => "{$l->account_id}-{$l->fiscal_period_id}");

        return [
            'periods' => $periods->map(fn ($p) => ['fiscal_period_id' => $p->id, 'period_no' => $p->period_no])->all(),
            'accounts' => $accounts->map(fn (Account $a) => [
                'account_id' => $a->id,
                'account_code' => $a->account_code,
                'account_name' => $a->account_name,
                'cells' => $periods->map(function ($p) use ($existing, $a) {
                    $line = $existing->get("{$a->id}-{$p->id}");

                    return ['fiscal_period_id' => $p->id, 'amount' => $line ? (float) $line->amount : null];
                })->all(),
            ])->all(),
        ];
    }
}
