<?php

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\BalanceSheetService;
use App\Modules\Accounting\Services\CombinedReportPeriodResolver;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** §3N — Balance Sheet. "Combined" sums the same report across every active company, matching periods by period_no (see CombinedReportPeriodResolver). */
class BalanceSheetController extends BaseReportController
{
    public function __construct(
        private readonly BalanceSheetService $service,
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

        return Inertia::render('Accounting/Reports/BalanceSheet', [
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
        foreach (['assets' => 'Aset', 'liabilities' => 'Liabilitas', 'equity' => 'Ekuitas'] as $key => $label) {
            foreach ($report['current'][$key] as $r) {
                $rows[] = [$label, $r['account_code'], $r['account_name'], $r['balance']];
            }
        }
        $rows[] = ['', '', 'Total Assets', $report['current']['totalAssets']];
        $rows[] = ['', '', 'Total Liabilities + Equity', $report['current']['totalLiabilitiesAndEquity']];

        return $this->csvResponse("balance-sheet-period-{$period->period_no}.csv", ['Section', 'Code', 'Account', 'Balance'], $rows);
    }

    private function combinedReport(Collection $companies, FiscalPeriod $referencePeriod): array
    {
        $currentSections = ['assets' => [], 'liabilities' => [], 'equity' => []];
        $priorSections = ['assets' => [], 'liabilities' => [], 'equity' => []];
        $hasPrior = false;

        foreach ($companies as $c) {
            $period = $this->periodResolver->resolve($c, $referencePeriod);
            if (! $period) {
                continue;
            }
            $generated = $this->service->generate($c, $period);
            foreach (['assets', 'liabilities', 'equity'] as $section) {
                $currentSections[$section] = $this->mergeRows($currentSections[$section], $generated['current'][$section]);
            }
            if ($generated['prior']) {
                $hasPrior = true;
                foreach (['assets', 'liabilities', 'equity'] as $section) {
                    $priorSections[$section] = $this->mergeRows($priorSections[$section], $generated['prior'][$section]);
                }
            }
        }

        $build = fn (array $sections, string $label) => [
            'periodLabel' => $label,
            'asOfDate' => $referencePeriod->end_date->toDateString(),
            'assets' => $sections['assets'],
            'liabilities' => $sections['liabilities'],
            'equity' => $sections['equity'],
            'totalAssets' => $t1 = round(array_sum(array_column($sections['assets'], 'balance')), 2),
            'totalLiabilitiesAndEquity' => $t2 = round(array_sum(array_column($sections['liabilities'], 'balance')) + array_sum(array_column($sections['equity'], 'balance')), 2),
            'variance' => round($t1 - $t2, 2),
        ];

        return [
            'current' => $build($currentSections, "Period {$referencePeriod->period_no} (combined)"),
            'prior' => $hasPrior ? $build($priorSections, 'Prior period (combined)') : null,
        ];
    }
}
