<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\KpiValue;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Requests\StoreKpiValueRequest;
use App\Modules\Performance\Requests\UpdateKpiValueRequest;
use App\Modules\Performance\Services\KpiValueService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3D KPI Actuals Capture (Entry — MVP manual). */
class KpiValueController extends Controller
{
    private const SORTABLE = ['actual_value', 'entered_at'];

    public function __construct(protected KpiValueService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('kpi_id', 'period_id', 'subject_type', 'sort', 'direction', 'per_page');

        $values = KpiValue::query()
            ->with(['kpi:id,name,unit', 'period:id,label', 'enteredBy:id,name'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'entered_at', 'desc'),
                fn ($query) => $query->orderByDesc('entered_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (KpiValue $v) => [
                'id' => $v->id,
                'kpi_name' => $v->kpi?->name,
                'kpi_unit' => $v->kpi?->unit,
                'subject_label' => $this->subjectLabel($v->subject_type, $v->subject_id),
                'period_label' => $v->period?->label,
                'actual_value' => (float) $v->actual_value,
                'source' => $v->source,
                'entered_by_name' => $v->enteredBy?->name,
                'entered_at_formatted' => $v->entered_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Performance/KpiValues/Index', [
            'values' => $values,
            'filters' => $filters,
            'kpis' => KpiDefinition::query()->orderBy('name')->get(['id', 'name']),
            'periods' => Period::query()->orderByDesc('start_date')->get(['id', 'label']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/KpiValues/Create', $this->formProps());
    }

    public function store(StoreKpiValueRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('performance.kpiValues.index')->with('success', 'Actual value recorded.');
    }

    public function edit(KpiValue $kpiValue): Response
    {
        return Inertia::render('Performance/KpiValues/Edit', [
            ...$this->formProps(),
            'value' => [
                'id' => $kpiValue->id,
                'kpi_id' => $kpiValue->kpi_id,
                'subject_type' => $kpiValue->subject_type,
                'subject_id' => $kpiValue->subject_id,
                'period_id' => $kpiValue->period_id,
                'actual_value' => (float) $kpiValue->actual_value,
            ],
        ]);
    }

    public function update(UpdateKpiValueRequest $request, KpiValue $kpiValue)
    {
        $this->service->update($kpiValue, $request->validated());

        return redirect()->route('performance.kpiValues.index')->with('success', 'Actual value updated.');
    }

    public function destroy(KpiValue $kpiValue)
    {
        $this->service->delete($kpiValue);

        return redirect()->route('performance.kpiValues.index')->with('success', 'Actual value deleted.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'kpis' => KpiDefinition::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit']),
            'periods' => Period::query()->where('is_active', true)->orderByDesc('start_date')->get(['id', 'label']),
            'orgUnits' => OrgUnit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->where('employment_status', Employee::STATUS_ACTIVE)->orderBy('full_name')->get(['id', 'full_name', 'employee_no']),
        ];
    }

    private function subjectLabel(string $subjectType, ?int $subjectId): string
    {
        return match ($subjectType) {
            KpiValue::SUBJECT_COMPANY => 'Company',
            KpiValue::SUBJECT_ORG_UNIT => OrgUnit::query()->find($subjectId)?->name ?? 'Unknown org unit',
            KpiValue::SUBJECT_EMPLOYEE => Employee::query()->find($subjectId)?->full_name ?? 'Unknown employee',
            default => 'Unknown subject',
        };
    }
}
