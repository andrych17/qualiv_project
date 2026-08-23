<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Requests\StoreFiscalYearRequest;
use App\Modules\Accounting\Requests\UpdatePeriodStatusRequest;
use App\Modules\Accounting\Services\FiscalYearService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3B fiscal calendar — a fiscal year always ships with its 12 monthly periods (§3O period locking lives on the period row). */
class FiscalYearController extends Controller
{
    public function __construct(private readonly FiscalYearService $service) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) ($request->integer('company_id') ?: $companies->first()?->id);

        $fiscalYears = FiscalYear::query()
            ->where('company_id', $companyId)
            ->with(['periods' => fn ($q) => $q->orderBy('period_no')])
            ->orderByDesc('year')
            ->get();

        return Inertia::render('Accounting/FiscalYears/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'fiscalYears' => $fiscalYears->map(fn (FiscalYear $fy) => [
                'id' => $fy->id,
                'year' => $fy->year,
                'start_date' => $fy->start_date->toDateString(),
                'end_date' => $fy->end_date->toDateString(),
                'status' => $fy->status,
                'periods' => $fy->periods->map(fn (FiscalPeriod $p) => [
                    'id' => $p->id,
                    'period_no' => $p->period_no,
                    'start_date' => $p->start_date->toDateString(),
                    'end_date' => $p->end_date->toDateString(),
                    'status' => $p->status,
                ]),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Accounting/FiscalYears/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $request->integer('company_id') ?: null,
        ]);
    }

    public function store(StoreFiscalYearRequest $request)
    {
        $data = $request->validated();
        $this->service->create($data['company_id'], $data['year'], $data['start_date']);

        return redirect()->route('accounting.fiscal-years.index', ['company_id' => $data['company_id']])
            ->with('success', 'Fiscal year created with 12 monthly periods.');
    }

    public function updatePeriodStatus(UpdatePeriodStatusRequest $request, FiscalPeriod $period)
    {
        $this->service->setPeriodStatus($period, $request->validated()['status']);

        return redirect()->route('accounting.fiscal-years.index', ['company_id' => $period->company_id])
            ->with('success', 'Period status updated.');
    }
}
