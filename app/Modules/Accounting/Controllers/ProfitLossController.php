<?php

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\CombinedReportPeriodResolver;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\ProfitLossService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** §3N — P&L. "Combined" sums the same report across every active company, matching periods by period_no (see CombinedReportPeriodResolver). */
class ProfitLossController extends BaseReportController
{
    public function __construct(
        private readonly ProfitLossService $service,
        private readonly CompanyContextService $companyContext,
        private readonly CombinedReportPeriodResolver $periodResolver,
    ) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);
        $company = Company::query()->findOrFail($companyId);
        $combined = $request->boolean('combined');

        $periods = $this->periodOptions($companyId);
        $periodId = $request->integer('fiscal_period_id') ?: $periods->first()['value'] ?? null;

        $report = null;
        if ($periodId) {
            $period = FiscalPeriod::query()->findOrFail($periodId);
            $report = $combined ? $this->combinedReport($companies, $period) : $this->service->generate($company, $period);
        }

        return Inertia::render('Accounting/Reports/ProfitLoss', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'combined' => $combined,
            'periods' => $periods,
            'selectedPeriodId' => $periodId,
            'report' => $report,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);
        $company = Company::query()->findOrFail($companyId);
        $combined = $request->boolean('combined');
        $period = FiscalPeriod::query()->findOrFail($request->integer('fiscal_period_id'));

        $report = $combined ? $this->combinedReport($companies, $period) : $this->service->generate($company, $period);

        $rows = [];
        foreach (['revenue' => 'Revenue', 'cogs' => 'COGS', 'expense' => 'Expense'] as $key => $label) {
            foreach ($report['current'][$key] as $r) {
                $rows[] = [$label, $r['account_code'], $r['account_name'], $r['balance']];
            }
        }
        $rows[] = ['', '', 'Total Revenue', $report['current']['totalRevenue']];
        $rows[] = ['', '', 'Total COGS', $report['current']['totalCogs']];
        $rows[] = ['', '', 'Gross Profit', $report['current']['grossProfit']];
        $rows[] = ['', '', 'Total Expense', $report['current']['totalExpense']];
        $rows[] = ['', '', 'Net Income', $report['current']['netIncome']];

        return $this->csvResponse("profit-loss-period-{$period->period_no}.csv", ['Section', 'Code', 'Account', 'Amount'], $rows);
    }

    private function combinedReport(Collection $companies, FiscalPeriod $referencePeriod): array
    {
        $currentSections = ['revenue' => [], 'cogs' => [], 'expense' => []];
        $priorSections = ['revenue' => [], 'cogs' => [], 'expense' => []];
        $hasPrior = false;

        foreach ($companies as $c) {
            $period = $this->periodResolver->resolve($c, $referencePeriod);
            if (! $period) {
                continue;
            }
            $generated = $this->service->generate($c, $period);
            foreach (['revenue', 'cogs', 'expense'] as $section) {
                $currentSections[$section] = $this->mergeRows($currentSections[$section], $generated['current'][$section]);
            }
            if ($generated['prior']) {
                $hasPrior = true;
                foreach (['revenue', 'cogs', 'expense'] as $section) {
                    $priorSections[$section] = $this->mergeRows($priorSections[$section], $generated['prior'][$section]);
                }
            }
        }

        $build = fn (array $sections, string $label) => [
            'periodLabel' => $label,
            'periodEnd' => $referencePeriod->end_date->toDateString(),
            'revenue' => $sections['revenue'],
            'cogs' => $sections['cogs'],
            'expense' => $sections['expense'],
            'totalRevenue' => $tr = round(array_sum(array_column($sections['revenue'], 'balance')), 2),
            'totalCogs' => $tc = round(array_sum(array_column($sections['cogs'], 'balance')), 2),
            'grossProfit' => $gp = round($tr - $tc, 2),
            'totalExpense' => $te = round(array_sum(array_column($sections['expense'], 'balance')), 2),
            'netIncome' => round($gp - $te, 2),
        ];

        return [
            'current' => $build($currentSections, "Period {$referencePeriod->period_no} (combined)"),
            'prior' => $hasPrior ? $build($priorSections, 'Prior period (combined)') : null,
        ];
    }
}
