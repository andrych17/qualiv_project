<?php

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\CombinedReportPeriodResolver;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\TrialBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** §3N — Trial Balance. "Combined" sums the same report across every active company, matching periods by period_no (see CombinedReportPeriodResolver). */
class TrialBalanceController extends BaseReportController
{
    public function __construct(
        private readonly TrialBalanceService $service,
        private readonly CompanyContextService $companyContext,
        private readonly CombinedReportPeriodResolver $periodResolver,
    ) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);
        $company = $companyId ? Company::query()->find($companyId) : null;
        $combined = $request->boolean('combined');

        $periods = $companyId ? $this->periodOptions($companyId) : collect();
        $periodId = $request->integer('fiscal_period_id') ?: $periods->first()['value'] ?? null;

        $report = null;
        if ($company && $periodId) {
            $period = FiscalPeriod::query()->find($periodId);
            if ($period) {
                $report = $combined ? $this->combinedReport($companies, $period) : $this->service->generate($company, $period);
            }
        }

        return Inertia::render('Accounting/Reports/TrialBalance', [
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

        $rows = array_map(fn (array $r) => [$r['account_code'], $r['account_name'], $r['debit'] ?: '', $r['credit'] ?: ''], $report['rows']);
        $rows[] = ['', 'Total', $report['totalDebit'], $report['totalCredit']];

        return $this->csvResponse("trial-balance-period-{$period->period_no}.csv", ['Code', 'Account', 'Debit', 'Credit'], $rows);
    }

    private function combinedReport(Collection $companies, FiscalPeriod $referencePeriod): array
    {
        $rows = collect();
        foreach ($companies as $c) {
            $period = $this->periodResolver->resolve($c, $referencePeriod);
            if (! $period) {
                continue;
            }
            foreach ($this->service->generate($c, $period)['rows'] as $row) {
                $key = $row['account_code'];
                $existing = $rows->get($key, ['account_id' => null, 'account_code' => $row['account_code'], 'account_name' => $row['account_name'], 'debit' => 0.0, 'credit' => 0.0]);
                $existing['debit'] = round($existing['debit'] + $row['debit'], 2);
                $existing['credit'] = round($existing['credit'] + $row['credit'], 2);
                $rows->put($key, $existing);
            }
        }

        $rows = $rows->sortKeys()->values()->all();

        return ['rows' => $rows, 'totalDebit' => round(array_sum(array_column($rows, 'debit')), 2), 'totalCredit' => round(array_sum(array_column($rows, 'credit')), 2)];
    }
}
