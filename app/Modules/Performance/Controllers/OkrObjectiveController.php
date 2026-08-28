<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\OkrCycle;
use App\Modules\Performance\Models\OkrObjective;
use App\Modules\Performance\Requests\StoreOkrObjectiveRequest;
use App\Modules\Performance\Requests\UpdateOkrObjectiveRequest;
use App\Modules\Performance\Requests\UpdateOkrObjectiveStatusRequest;
use App\Modules\Performance\Services\OkrObjectiveService;
use App\Modules\Performance\Services\OkrProgressService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3E OKR Objectives — Board (Kanban by status) + List + Alignment (indented tree), all fed from
 * one flat, non-paginated array for the selected cycle — same "flat-array-with-client-side-filter"
 * architecture as CRM's Lead board, since a cycle's objective count stays small at MVP volumes and
 * the Alignment tree needs every objective in the cycle in memory to build parent/child chains.
 */
class OkrObjectiveController extends Controller
{
    public function __construct(
        protected OkrObjectiveService $service,
        protected OkrProgressService $progress,
    ) {}

    public function index(Request $request): Response
    {
        $cycleId = (int) ($request->query('cycle_id') ?: (OkrCycle::query()->where('is_active', true)->orderByDesc('start_date')->value('id') ?? OkrCycle::query()->orderByDesc('start_date')->value('id')));

        $objectives = OkrObjective::query()
            ->with(['keyResults', 'parent:id,objective_text'])
            ->where('cycle_id', $cycleId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (OkrObjective $o) => [
                'id' => $o->id,
                'objective_text' => $o->objective_text,
                'subject_label' => $this->subjectLabel($o->subject_type, $o->subject_id),
                'status' => $o->status,
                'parent_okr_id' => $o->parent_okr_id,
                'parent_text' => $o->parent?->objective_text,
                'key_results_count' => $o->keyResults->count(),
                'progress' => $this->progress->objectiveProgress($o),
            ]);

        return Inertia::render('Performance/OkrObjectives/Index', [
            'objectives' => $objectives,
            'cycleId' => $cycleId ?: null,
            'cycles' => OkrCycle::query()->orderByDesc('start_date')->get(['id', 'label']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/OkrObjectives/Create', $this->formProps());
    }

    public function store(StoreOkrObjectiveRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('performance.okrObjectives.index')->with('success', 'Objective created.');
    }

    public function edit(OkrObjective $okrObjective): Response
    {
        $okrObjective->load('keyResults');

        return Inertia::render('Performance/OkrObjectives/Edit', [
            ...$this->formProps(),
            'objective' => [
                'id' => $okrObjective->id,
                'cycle_id' => $okrObjective->cycle_id,
                'subject_type' => $okrObjective->subject_type,
                'subject_id' => $okrObjective->subject_id,
                'objective_text' => $okrObjective->objective_text,
                'parent_okr_id' => $okrObjective->parent_okr_id,
                'status' => $okrObjective->status,
                'key_results' => $okrObjective->keyResults->map(fn ($kr) => [
                    'description' => $kr->description,
                    'metric_type' => $kr->metric_type,
                    'start_value' => (float) $kr->start_value,
                    'current_value' => (float) $kr->current_value,
                    'target_value' => (float) $kr->target_value,
                    'weight' => (float) $kr->weight,
                ]),
            ],
        ]);
    }

    public function update(UpdateOkrObjectiveRequest $request, OkrObjective $okrObjective)
    {
        $this->service->update($okrObjective, $request->validated());

        return redirect()->route('performance.okrObjectives.index')->with('success', 'Objective updated.');
    }

    public function destroy(OkrObjective $okrObjective)
    {
        $this->service->delete($okrObjective);

        return redirect()->route('performance.okrObjectives.index')->with('success', 'Objective deleted.');
    }

    public function updateStatus(UpdateOkrObjectiveStatusRequest $request, OkrObjective $okrObjective)
    {
        $this->service->updateStatus($okrObjective, $request->validated()['status']);

        return back()->with('success', 'Objective status updated.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'cycles' => OkrCycle::query()->where('is_active', true)->orderByDesc('start_date')->get(['id', 'label']),
            'orgUnits' => OrgUnit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->where('employment_status', Employee::STATUS_ACTIVE)->orderBy('full_name')->get(['id', 'full_name', 'employee_no']),
            'parentOptions' => OkrObjective::query()->orderByDesc('id')->get(['id', 'objective_text']),
        ];
    }

    private function subjectLabel(string $subjectType, ?int $subjectId): string
    {
        return match ($subjectType) {
            OkrObjective::SUBJECT_COMPANY => 'Company',
            OkrObjective::SUBJECT_ORG_UNIT => OrgUnit::query()->find($subjectId)?->name ?? 'Unknown org unit',
            OkrObjective::SUBJECT_EMPLOYEE => Employee::query()->find($subjectId)?->full_name ?? 'Unknown employee',
            default => 'Unknown subject',
        };
    }
}
