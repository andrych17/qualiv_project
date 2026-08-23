<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\TaxPeriod;
use App\Modules\Accounting\Requests\StoreTaxPeriodRequest;
use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\Accounting\Services\TaxPeriodService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3M tax period register (masa pajak) — due-date reminders via WNE are a later build; this is the register itself. */
class TaxPeriodController extends Controller
{
    public function __construct(private readonly TaxPeriodService $service, private readonly CompanyContextService $companyContext) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) $this->companyContext->resolve($request, $companies);

        $periods = TaxPeriod::query()->where('company_id', $companyId)->orderByDesc('masa_pajak')->get();

        return Inertia::render('Accounting/TaxPeriods/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'periods' => $periods->map(fn (TaxPeriod $p) => [
                'id' => $p->id,
                'obligation_type' => $p->obligation_type,
                'masa_pajak' => $p->masa_pajak,
                'due_date' => $p->due_date->toDateString(),
                'filing_status' => $p->isLate() ? 'late' : $p->filing_status,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Accounting/TaxPeriods/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $request->integer('company_id') ?: null,
        ]);
    }

    public function store(StoreTaxPeriodRequest $request)
    {
        $data = $request->validated();
        $this->service->ensurePeriod($data['company_id'], $data['obligation_type'], $data['masa_pajak']);

        return redirect()->route('accounting.tax-periods.index', ['company_id' => $data['company_id']])
            ->with('success', 'Tax period registered.');
    }

    public function markFiled(TaxPeriod $period)
    {
        $companyId = $period->company_id;
        $this->service->markFiled($period);

        return redirect()->route('accounting.tax-periods.index', ['company_id' => $companyId])->with('success', 'Period marked filed.');
    }
}
