<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\OkrObjective;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Models\Perspective;
use App\Modules\Performance\Models\Scorecard;
use App\Modules\Performance\Requests\StoreScorecardRequest;
use App\Modules\Performance\Requests\UpdateScorecardRequest;
use App\Modules\Performance\Services\ScorecardScoringService;
use App\Modules\Performance\Services\ScorecardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3F Scorecard — unlike every other Performance controller, this keeps `show` (the Viewer,
 * §3F's own named grid) as a distinct route from `edit` (the Builder) — composing weights and
 * viewing live computed scores are genuinely different screens here, not "draft vs read-only."
 */
class ScorecardController extends Controller
{
    public function __construct(
        protected ScorecardService $service,
        protected ScorecardScoringService $scoring,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('subject_type');

        $scorecards = Scorecard::query()
            ->with('period:id,label')
            ->withCount('items')
            ->filter($filters)
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Scorecard $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'subject_label' => $this->subjectLabel($s->subject_type, $s->subject_id),
                'period_label' => $s->period?->label,
                'items_count' => $s->items_count,
            ]);

        return Inertia::render('Performance/Scorecards/Index', [
            'scorecards' => $scorecards,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/Scorecards/Create', $this->formProps());
    }

    public function store(StoreScorecardRequest $request)
    {
        $scorecard = $this->service->create($request->validated());

        return redirect()->route('performance.scorecards.show', $scorecard)->with('success', 'Scorecard created.');
    }

    public function show(Scorecard $scorecard): Response
    {
        $scored = $this->scoring->score($scorecard);

        return Inertia::render('Performance/Scorecards/Show', [
            'scorecard' => [
                'id' => $scorecard->id,
                'name' => $scorecard->name,
                'subject_label' => $this->subjectLabel($scorecard->subject_type, $scorecard->subject_id),
                'period_label' => $scorecard->period?->label,
            ],
            'scored' => $scored,
        ]);
    }

    public function edit(Scorecard $scorecard): Response
    {
        $scorecard->load('items');

        return Inertia::render('Performance/Scorecards/Edit', [
            ...$this->formProps(),
            'scorecard' => [
                'id' => $scorecard->id,
                'name' => $scorecard->name,
                'subject_type' => $scorecard->subject_type,
                'subject_id' => $scorecard->subject_id,
                'period_id' => $scorecard->period_id,
                'items' => $scorecard->items->map(fn ($item) => [
                    'perspective_id' => $item->perspective_id,
                    'kpi_id' => $item->kpi_id,
                    'okr_id' => $item->okr_id,
                    'weight' => (float) $item->weight,
                ]),
            ],
        ]);
    }

    public function update(UpdateScorecardRequest $request, Scorecard $scorecard)
    {
        $this->service->update($scorecard, $request->validated());

        return redirect()->route('performance.scorecards.show', $scorecard)->with('success', 'Scorecard updated.');
    }

    public function destroy(Scorecard $scorecard)
    {
        $this->service->delete($scorecard);

        return redirect()->route('performance.scorecards.index')->with('success', 'Scorecard deleted.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'perspectives' => Perspective::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'kpis' => KpiDefinition::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'okrObjectives' => OkrObjective::query()->orderByDesc('id')->get(['id', 'objective_text']),
            'periods' => Period::query()->where('is_active', true)->orderByDesc('start_date')->get(['id', 'label']),
            'orgUnits' => OrgUnit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->where('employment_status', Employee::STATUS_ACTIVE)->orderBy('full_name')->get(['id', 'full_name', 'employee_no']),
        ];
    }

    private function subjectLabel(string $subjectType, ?int $subjectId): string
    {
        return match ($subjectType) {
            Scorecard::SUBJECT_COMPANY => 'Company',
            Scorecard::SUBJECT_ORG_UNIT => OrgUnit::query()->find($subjectId)?->name ?? 'Unknown org unit',
            Scorecard::SUBJECT_EMPLOYEE => Employee::query()->find($subjectId)?->full_name ?? 'Unknown employee',
            default => 'Unknown subject',
        };
    }
}
