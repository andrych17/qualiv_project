<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Requests\StoreDepreciationRunRequest;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\DepreciationRunService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3G — the monthly depreciation batch trigger. Manual "run for period" v1 (no scheduler wiring yet — DepreciationRunService::runForPeriod() is the reusable unit a future cron just calls). */
class DepreciationRunController extends Controller
{
    public function __construct(private readonly DepreciationRunService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $periods = FiscalPeriod::query()
            ->where('company_id', $companyId)->where('status', FiscalPeriod::STATUS_OPEN)
            ->orderBy('start_date')
            ->get(['id', 'period_no', 'start_date', 'end_date'])
            ->map(fn (FiscalPeriod $p) => ['value' => $p->id, 'label' => "Period {$p->period_no} ({$p->start_date->toDateString()} – {$p->end_date->toDateString()})"]);

        $recentJournals = GlJournal::query()
            ->where('company_id', $companyId)->where('source', 'asset')
            ->orderByDesc('journal_date')->limit(20)
            ->get(['id', 'journal_date', 'memo'])
            ->map(fn (GlJournal $j) => ['id' => $j->id, 'journal_date' => $j->journal_date->toDateString(), 'memo' => $j->memo]);

        return Inertia::render('Accounting/DepreciationRuns/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'periods' => $periods,
            'recentJournals' => $recentJournals,
        ]);
    }

    public function store(StoreDepreciationRunRequest $request)
    {
        $period = FiscalPeriod::query()->findOrFail($request->input('fiscal_period_id'));
        $company = Company::query()->findOrFail($period->company_id);

        $result = $this->service->runForPeriod($company, $period, $request->user()->id);

        return redirect()->route('accounting.depreciation-runs.index', ['company_id' => $company->id])
            ->with('success', "Depreciation run complete: {$result['commercialCount']} commercial line(s) posted, {$result['fiscalCount']} fiscal schedule row(s) recorded.");
    }
}
