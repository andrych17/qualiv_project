<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Models\Target;
use App\Modules\Performance\Requests\StoreTargetRequest;
use App\Modules\Performance\Requests\UpdateTargetRequest;
use App\Modules\Performance\Services\TargetService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3C Targets (Entry) — assigns a KPI + target value to a subject/period; the "multi-level" mechanism. */
class TargetController extends Controller
{
    private const SORTABLE = ['target_value', 'created_at'];

    public function __construct(protected TargetService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('kpi_id', 'period_id', 'subject_type', 'sort', 'direction', 'per_page');

        $targets = Target::query()
            ->with(['kpi:id,name,unit,direction', 'period:id,label'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Target $t) => [
                'id' => $t->id,
                'kpi_name' => $t->kpi?->name,
                'kpi_unit' => $t->kpi?->unit,
                'subject_label' => $this->subjectLabel($t->subject_type, $t->subject_id),
                'period_label' => $t->period?->label,
                'target_value' => (float) $t->target_value,
                'stretch_value' => $t->stretch_value !== null ? (float) $t->stretch_value : null,
            ]);

        return Inertia::render('Performance/Targets/Index', [
            'targets' => $targets,
            'filters' => $filters,
            'kpis' => KpiDefinition::query()->orderBy('name')->get(['id', 'name']),
            'periods' => Period::query()->orderByDesc('start_date')->get(['id', 'label']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/Targets/Create', $this->formProps());
    }

    public function store(StoreTargetRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('performance.targets.index')->with('success', 'Target created.');
    }

    public function edit(Target $target): Response
    {
        return Inertia::render('Performance/Targets/Edit', [
            ...$this->formProps(),
            'target' => [
                'id' => $target->id,
                'kpi_id' => $target->kpi_id,
                'subject_type' => $target->subject_type,
                'subject_id' => $target->subject_id,
                'period_id' => $target->period_id,
                'target_value' => (float) $target->target_value,
                'stretch_value' => $target->stretch_value !== null ? (float) $target->stretch_value : null,
                'notes' => $target->notes,
            ],
        ]);
    }

    public function update(UpdateTargetRequest $request, Target $target)
    {
        $this->service->update($target, $request->validated());

        return redirect()->route('performance.targets.index')->with('success', 'Target updated.');
    }

    public function destroy(Target $target)
    {
        $this->service->delete($target);

        return redirect()->route('performance.targets.index')->with('success', 'Target deleted.');
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
            Target::SUBJECT_COMPANY => 'Company',
            Target::SUBJECT_ORG_UNIT => OrgUnit::query()->find($subjectId)?->name ?? 'Unknown org unit',
            Target::SUBJECT_EMPLOYEE => Employee::query()->find($subjectId)?->full_name ?? 'Unknown employee',
            default => 'Unknown subject',
        };
    }
}
