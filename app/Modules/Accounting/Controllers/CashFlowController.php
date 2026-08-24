<?php

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\CashFlowService;
use App\Modules\Accounting\Services\CombinedReportPeriodResolver;
use App\Modules\Accounting\Services\CompanyContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** §3N — Cash Flow Statement (indirect method). "Combined" sums every scalar line across companies — each company's own figures already tie out individually (see CashFlowService), and summation is linear so the combined totals tie out too. */
class CashFlowController extends BaseReportController
{
    private const FIELDS = [
        'netIncome', 'depreciationAddBack', 'disposalGainLossReversal', 'operatingOther', 'operatingTotal',
        'disposalProceeds', 'assetAdditions', 'investingTotal', 'financingTotal', 'netChange', 'actualCashChange', 'variance',
    ];

    public function __construct(
        private readonly CashFlowService $service,
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

        return Inertia::render('Accounting/Reports/CashFlow', [
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

        $labels = [
            'netIncome' => 'Net income', 'depreciationAddBack' => 'Depreciation add-back', 'disposalGainLossReversal' => 'Disposal gain/loss reversal',
            'operatingOther' => 'Other working-capital movement', 'operatingTotal' => 'Total operating',
            'disposalProceeds' => 'Disposal proceeds', 'assetAdditions' => 'Asset additions', 'investingTotal' => 'Total investing',
            'financingTotal' => 'Total financing', 'netChange' => 'Net change in cash', 'actualCashChange' => 'Actual cash-account movement', 'variance' => 'Variance',
        ];
        $rows = array_map(fn ($field, $label) => [$label, $report[$field]], array_keys($labels), $labels);

        return $this->csvResponse("cash-flow-period-{$period->period_no}.csv", ['Line', 'Amount'], $rows);
    }

    private function combinedReport(Collection $companies, FiscalPeriod $referencePeriod): array
    {
        $totals = array_fill_keys(self::FIELDS, 0.0);

        foreach ($companies as $c) {
            $period = $this->periodResolver->resolve($c, $referencePeriod);
            if (! $period) {
                continue;
            }
            $generated = $this->service->generate($c, $period);
            foreach (self::FIELDS as $field) {
                $totals[$field] = round($totals[$field] + $generated[$field], 2);
            }
        }

        return [
            'periodLabel' => "Period {$referencePeriod->period_no} (combined)",
            'periodEnd' => $referencePeriod->end_date->toDateString(),
            ...$totals,
        ];
    }
}
