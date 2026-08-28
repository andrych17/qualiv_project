<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\Budget;
use App\Modules\Performance\Models\Forecast;
use App\Modules\Performance\Models\ForecastLine;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Requests\ReviseForecastRequest;
use App\Modules\Performance\Requests\StoreForecastRequest;
use App\Modules\Performance\Services\ForecastService;
use App\Modules\Performance\Services\VarianceService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3H Forecast (Entry) — a trajectory linked to a Budget or a KPI; immutable once created, revised only as a new version. */
class ForecastController extends Controller
{
    private const SORTABLE = ['created_at'];

    public function __construct(
        protected ForecastService $service,
        protected VarianceService $variance,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('subject_type', 'series', 'sort', 'direction', 'per_page');

        $forecasts = Forecast::query()
            ->with(['budget:id,name', 'kpi:id,name', 'period:id,label'])
            ->withCount('lines')
            ->when(
                $filters['series'] ?? null,
                fn ($query) => $query->series((int) $filters['series']),
                fn ($query) => $query->where('is_latest', true),
            )
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Forecast $f) => [
                'id' => $f->id,
                'subject_label' => $this->subjectLabel($f->subject_type, $f->subject_id),
                'linked_label' => $f->budget ? "Budget: {$f->budget->name}" : "KPI: {$f->kpi?->name}",
                'period_label' => $f->period?->label,
                'version_no' => $f->version_no,
                'is_latest' => $f->is_latest,
                'lines_count' => $f->lines_count,
                'series_id' => $f->root_forecast_id ?? $f->id,
            ]);

        return Inertia::render('Performance/Forecasts/Index', [
            'forecasts' => $forecasts,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/Forecasts/Create', $this->formProps());
    }

    public function store(StoreForecastRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('performance.forecasts.index')->with('success', 'Forecast created.');
    }

    public function edit(Forecast $forecast): Response
    {
        $forecast->load(['lines.period', 'budget:id,name', 'kpi:id,name', 'period:id,label']);

        return Inertia::render('Performance/Forecasts/Edit', [
            'forecast' => $this->forecastProps($forecast),
        ]);
    }

    public function destroy(Forecast $forecast)
    {
        $this->service->delete($forecast);

        return redirect()->route('performance.forecasts.index')->with('success', 'Forecast deleted.');
    }

    public function reviseForm(Forecast $forecast): Response
    {
        $forecast->load(['lines.period', 'budget:id,name', 'kpi:id,name', 'period:id,label']);

        return Inertia::render('Performance/Forecasts/Revise', [
            'periods' => Period::query()->where('is_active', true)->orderByDesc('start_date')->get(['id', 'label']),
            'forecast' => $this->forecastProps($forecast),
        ]);
    }

    public function revise(ReviseForecastRequest $request, Forecast $forecast)
    {
        $newVersion = $this->service->revise($forecast, $request->validated());

        return redirect()->route('performance.forecasts.edit', $newVersion)->with('success', 'New forecast version created.');
    }

    /** @return array<string, mixed> */
    private function forecastProps(Forecast $forecast): array
    {
        return [
            'id' => $forecast->id,
            'subject_label' => $this->subjectLabel($forecast->subject_type, $forecast->subject_id),
            'linked_label' => $forecast->budget ? "Budget: {$forecast->budget->name}" : "KPI: {$forecast->kpi?->name}",
            'period_id' => $forecast->period_id,
            'period_label' => $forecast->period?->label,
            'version_no' => $forecast->version_no,
            'is_latest' => $forecast->is_latest,
            'notes' => $forecast->notes,
            'series_id' => $forecast->root_forecast_id ?? $forecast->id,
            'lines' => $forecast->lines->map(function (ForecastLine $line) {
                $result = $this->variance->evaluateForecastLine($line);

                return [
                    'id' => $line->id,
                    'period_id' => $line->period_id,
                    'period_label' => $line->period?->label,
                    'forecast_value' => (float) $line->forecast_value,
                    'variance' => $result === null ? null : [
                        'actual_value' => $result->actualValue,
                        'variance_pct' => $result->variancePct,
                        'status' => $result->status,
                    ],
                ];
            }),
        ];
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'budgets' => Budget::query()->orderByDesc('id')->get(['id', 'name', 'fiscal_year']),
            'kpis' => KpiDefinition::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'periods' => Period::query()->where('is_active', true)->orderByDesc('start_date')->get(['id', 'label']),
            'orgUnits' => OrgUnit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->where('employment_status', Employee::STATUS_ACTIVE)->orderBy('full_name')->get(['id', 'full_name', 'employee_no']),
        ];
    }

    private function subjectLabel(string $subjectType, ?int $subjectId): string
    {
        return match ($subjectType) {
            Forecast::SUBJECT_COMPANY => 'Company',
            Forecast::SUBJECT_ORG_UNIT => OrgUnit::query()->find($subjectId)?->name ?? 'Unknown org unit',
            Forecast::SUBJECT_EMPLOYEE => Employee::query()->find($subjectId)?->full_name ?? 'Unknown employee',
            default => 'Unknown subject',
        };
    }
}
