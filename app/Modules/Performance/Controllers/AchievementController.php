<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\Achievement;
use App\Modules\Performance\Models\BadgeDefinition;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\OkrObjective;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Requests\StoreAchievementRequest;
use App\Modules\Performance\Services\AchievementService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3I — the earned-badge log: every auto-award and manual award, newest first. */
class AchievementController extends Controller
{
    private const SORTABLE = ['earned_at'];

    public function __construct(protected AchievementService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('badge_id', 'subject_type', 'sort', 'direction', 'per_page');

        $achievements = Achievement::query()
            ->with(['badge:id,name,icon', 'kpi:id,name', 'okr:id,objective_text', 'period:id,label', 'awardedBy:id,name'])
            ->when($filters['badge_id'] ?? null, fn ($query, $badgeId) => $query->where('badge_id', $badgeId))
            ->when($filters['subject_type'] ?? null, fn ($query, $subjectType) => $query->where('subject_type', $subjectType))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'earned_at', 'desc'),
                fn ($query) => $query->orderByDesc('earned_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Achievement $a) => [
                'id' => $a->id,
                'badge_name' => $a->badge?->name,
                'badge_icon' => $a->badge?->icon,
                'subject_label' => $this->subjectLabel($a->subject_type, $a->subject_id),
                'kpi_name' => $a->kpi?->name,
                'okr_text' => $a->okr?->objective_text,
                'period_label' => $a->period?->label,
                'earned_at_formatted' => $a->earned_at?->format('d M Y H:i'),
                'awarded_by_name' => $a->awardedBy?->name,
                'is_auto' => $a->awarded_by === null,
            ]);

        return Inertia::render('Performance/Achievements/Index', [
            'achievements' => $achievements,
            'filters' => $filters,
            'badges' => BadgeDefinition::query()->orderBy('name')->get(['id', 'name']),
            ...$this->formProps(),
        ]);
    }

    public function store(StoreAchievementRequest $request)
    {
        $this->service->award($request->validated());

        return redirect()->route('performance.achievements.index')->with('success', 'Badge awarded.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'activeBadges' => BadgeDefinition::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'kpis' => KpiDefinition::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'okrs' => OkrObjective::query()->orderByDesc('id')->get(['id', 'objective_text']),
            'periods' => Period::query()->where('is_active', true)->orderByDesc('start_date')->get(['id', 'label']),
            'orgUnits' => OrgUnit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->where('employment_status', Employee::STATUS_ACTIVE)->orderBy('full_name')->get(['id', 'full_name', 'employee_no']),
        ];
    }

    private function subjectLabel(string $subjectType, ?int $subjectId): string
    {
        return match ($subjectType) {
            Achievement::SUBJECT_COMPANY => 'Company',
            Achievement::SUBJECT_ORG_UNIT => OrgUnit::query()->find($subjectId)?->name ?? 'Unknown org unit',
            Achievement::SUBJECT_EMPLOYEE => Employee::query()->find($subjectId)?->full_name ?? 'Unknown employee',
            default => 'Unknown subject',
        };
    }
}
