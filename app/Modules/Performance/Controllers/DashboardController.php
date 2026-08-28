<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\Achievement;
use App\Modules\Performance\Models\Budget;
use App\Modules\Performance\Models\BudgetLine;
use App\Modules\Performance\Models\OkrCycle;
use App\Modules\Performance\Models\OkrObjective;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Models\Perspective;
use App\Modules\Performance\Models\Scorecard;
use App\Modules\Performance\Models\Target;
use App\Modules\Performance\Services\OkrProgressService;
use App\Modules\Performance\Services\ScorecardScoringService;
use App\Modules\Performance\Services\VarianceService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3A Main Dashboard — read-only aggregate over every other §3 engine built so far (§3B–§3H);
 * adds no tables of its own, same posture as Inventory's own §3A.
 *
 * Two scope decisions, both documented rather than silently done:
 * - Spec's single "period" filter can't drive every section as-is: Budget/KPI/Forecast are
 *   period-scoped (`PERF.periods`) but OKR is cycle-scoped (`PERF.okr_cycles`, a deliberately
 *   separate concept — see that migration's own docblock). This dashboard exposes both filters
 *   (`period_id`, `cycle_id`) rather than forcing one to stand in for the other.
 * - The row-click drawer's "trend (period-over-period)" belongs to a not-yet-built
 *   period-history view — a materially bigger feature than a read-only rollup, left as a
 *   documented gap (rows link straight to their owning record's edit page instead of opening a
 *   drawer). The "recent Achievements feed" gap this docblock used to flag is now closed: §3I
 *   shipped, see recentAchievements() below.
 *
 * Budget vs Actual matches `BudgetLine.period_id` directly to the selected period rather than
 * matching `Budget.fiscal_year`/`fiscal_quarter` — a line already pins the exact period it
 * belongs to, regardless of whether its parent Budget is an annual or quarterly one, so this
 * sidesteps the granularity mismatch entirely.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected VarianceService $variance,
        protected OkrProgressService $okrProgress,
        protected ScorecardScoringService $scoring,
    ) {}

    public function __invoke(Request $request): Response
    {
        $periodId = (int) ($request->query('period_id') ?: (Period::query()->where('is_active', true)->orderByDesc('start_date')->value('id') ?? Period::query()->orderByDesc('start_date')->value('id')));
        $cycleId = (int) ($request->query('cycle_id') ?: (OkrCycle::query()->where('is_active', true)->orderByDesc('start_date')->value('id') ?? OkrCycle::query()->orderByDesc('start_date')->value('id')));
        $subjectType = $request->query('subject_type', Target::SUBJECT_COMPANY);
        $subjectId = $request->query('subject_id') ? (int) $request->query('subject_id') : null;
        $perspectiveId = $request->query('perspective_id') ? (int) $request->query('perspective_id') : null;

        $kpiRows = $this->kpiRows($periodId, $subjectType, $subjectId, $perspectiveId);
        $okrRows = $this->okrRows($cycleId, $subjectType, $subjectId);
        $budgetRows = $this->budgetRows($periodId, $subjectType, $subjectId);
        $scorecardRows = $this->scorecardRows($periodId, $subjectType, $subjectId);

        return Inertia::render('Performance/Dashboard', [
            'filters' => [
                'period_id' => $periodId ?: null,
                'cycle_id' => $cycleId ?: null,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'perspective_id' => $perspectiveId,
            ],
            'periods' => Period::query()->orderByDesc('start_date')->get(['id', 'label']),
            'cycles' => OkrCycle::query()->orderByDesc('start_date')->get(['id', 'label']),
            'perspectives' => Perspective::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'orgUnits' => OrgUnit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->where('employment_status', Employee::STATUS_ACTIVE)->orderBy('full_name')->get(['id', 'full_name', 'employee_no']),
            'metrics' => $this->metrics($kpiRows, $okrRows, $budgetRows, $scorecardRows),
            'needsAttention' => $this->needsAttention($kpiRows, $okrRows, $budgetRows),
            'kpiRows' => $kpiRows->values()->all(),
            'okrRows' => $okrRows->values()->all(),
            'budgetRows' => $budgetRows->values()->all(),
            'scorecardRows' => $scorecardRows->values()->all(),
            'recentAchievements' => $this->recentAchievements($subjectType, $subjectId),
        ]);
    }

    /**
     * §3I — last 5 badges earned by this subject, most recent first. Not period/cycle-scoped
     * like the sections above: an achievement can be tied to a KPI's period, an OKR (cycle-less
     * by design), or neither (a manual award), so "recent" is simply this subject's own history.
     *
     * @return list<array<string, mixed>>
     */
    private function recentAchievements(string $subjectType, ?int $subjectId): array
    {
        return Achievement::query()
            ->with(['badge:id,name,icon', 'kpi:id,name', 'okr:id,objective_text'])
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('earned_at')
            ->limit(5)
            ->get()
            ->map(fn (Achievement $a) => [
                'id' => $a->id,
                'badge_name' => $a->badge?->name,
                'badge_icon' => $a->badge?->icon,
                'context' => $a->kpi?->name ?? $a->okr?->objective_text,
                'earned_at_formatted' => $a->earned_at?->format('d M Y'),
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function kpiRows(int $periodId, string $subjectType, ?int $subjectId, ?int $perspectiveId): Collection
    {
        return Target::query()
            ->with('kpi:id,name,unit,perspective_id')
            ->where('period_id', $periodId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->when($perspectiveId, fn ($q) => $q->whereHas('kpi', fn ($q) => $q->where('perspective_id', $perspectiveId)))
            ->get()
            ->map(function (Target $target) use ($subjectType, $subjectId, $periodId) {
                $result = $this->variance->evaluateKpi($subjectType, $subjectId, $target->kpi_id, $periodId);

                return [
                    'id' => $target->id,
                    'kpi_id' => $target->kpi_id,
                    'kpi_name' => $target->kpi?->name,
                    'target_value' => (float) $target->target_value,
                    'actual_value' => $result?->actualValue,
                    'variance_pct' => $result?->variancePct,
                    'status' => $result?->status ?? 'pending', // no actual recorded yet — distinct from a scored status
                    'href' => route('performance.targets.edit', $target->id),
                ];
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function okrRows(int $cycleId, string $subjectType, ?int $subjectId): Collection
    {
        return OkrObjective::query()
            ->with('keyResults')
            ->where('cycle_id', $cycleId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->get()
            ->map(fn (OkrObjective $objective) => [
                'id' => $objective->id,
                'objective_text' => $objective->objective_text,
                'status' => $objective->status,
                'progress' => $this->okrProgress->objectiveProgress($objective),
                'href' => route('performance.okrObjectives.edit', $objective->id),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function budgetRows(int $periodId, string $subjectType, ?int $subjectId): Collection
    {
        return BudgetLine::query()
            ->with('budget:id,name,subject_type,subject_id')
            ->whereHas('budget', fn ($q) => $q->where('subject_type', $subjectType)->where('subject_id', $subjectId))
            ->where('period_id', $periodId)
            ->get()
            ->map(function (BudgetLine $line) {
                $result = $this->variance->evaluateBudgetLine($line);

                return [
                    'id' => $line->id,
                    'budget_id' => $line->budget_id,
                    'budget_name' => $line->budget?->name,
                    'category' => $line->category,
                    'amount_planned' => (float) $line->amount_planned,
                    'actual_value' => $result?->actualValue,
                    'variance_pct' => $result?->variancePct,
                    'status' => $result?->status ?? 'pending',
                    'href' => route('performance.budgets.edit', $line->budget_id),
                ];
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function scorecardRows(int $periodId, string $subjectType, ?int $subjectId): Collection
    {
        return Scorecard::query()
            ->where('period_id', $periodId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->get()
            ->map(function (Scorecard $scorecard) {
                $scored = $this->scoring->score($scorecard);

                return [
                    'id' => $scorecard->id,
                    'name' => $scorecard->name,
                    'overall_score' => $scored['overall_score'],
                    'scored_perspectives' => $scored['scored_perspectives'],
                    'total_perspectives' => $scored['total_perspectives'],
                    'href' => route('performance.scorecards.show', $scorecard->id),
                ];
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $kpiRows
     * @param  Collection<int, array<string, mixed>>  $okrRows
     * @param  Collection<int, array<string, mixed>>  $budgetRows
     * @param  Collection<int, array<string, mixed>>  $scorecardRows
     * @return array<string, mixed>
     */
    private function metrics(Collection $kpiRows, Collection $okrRows, Collection $budgetRows, Collection $scorecardRows): array
    {
        $scoredScorecards = $scorecardRows->pluck('overall_score')->filter(fn ($v) => $v !== null);

        $resolvedBudgetLines = $budgetRows->filter(fn ($r) => $r['actual_value'] !== null);
        $sumPlanned = $resolvedBudgetLines->sum('amount_planned');
        $sumActual = $resolvedBudgetLines->sum('actual_value');
        $budgetVariancePct = $sumPlanned > 0.00005 ? (($sumActual - $sumPlanned) / $sumPlanned) * 100 : null;

        return [
            'overall_scorecard_pct' => $scoredScorecards->isEmpty() ? null : round($scoredScorecards->avg(), 2),
            'budget_variance_pct' => $budgetVariancePct === null ? null : round($budgetVariancePct, 2),
            'okrs_on_track' => $okrRows->where('status', OkrObjective::STATUS_ON_TRACK)->count(),
            'okrs_total' => $okrRows->count(),
            'open_breaches' => $kpiRows->where('status', 'breach')->count()
                + $budgetRows->where('status', 'breach')->count()
                + $okrRows->where('status', OkrObjective::STATUS_OFF_TRACK)->count(),
        ];
    }

    /**
     * "Needs attention always surfaces breaches first regardless of chosen sort" (§3A) — the
     * three breach-equivalent statuses (KPI `breach`, Budget `breach`, OKR `off_track`) always
     * sort ahead of the three warning-equivalent ones (`warning`, `warning`, `at_risk`).
     *
     * @param  Collection<int, array<string, mixed>>  $kpiRows
     * @param  Collection<int, array<string, mixed>>  $okrRows
     * @param  Collection<int, array<string, mixed>>  $budgetRows
     * @return list<array<string, mixed>>
     */
    private function needsAttention(Collection $kpiRows, Collection $okrRows, Collection $budgetRows): array
    {
        $items = collect();

        foreach ($kpiRows->whereIn('status', ['warning', 'breach']) as $row) {
            $items->push([
                'type' => 'KPI', 'label' => $row['kpi_name'], 'detail' => 'Variance '.round($row['variance_pct'] ?? 0, 1).'%',
                'rail' => $row['status'] === 'breach' ? 'danger' : 'warning', 'href' => $row['href'],
            ]);
        }

        foreach ($budgetRows->whereIn('status', ['warning', 'breach']) as $row) {
            $items->push([
                'type' => 'Budget', 'label' => "{$row['budget_name']} — {$row['category']}", 'detail' => 'Variance '.round($row['variance_pct'] ?? 0, 1).'%',
                'rail' => $row['status'] === 'breach' ? 'danger' : 'warning', 'href' => $row['href'],
            ]);
        }

        foreach ($okrRows->whereIn('status', [OkrObjective::STATUS_AT_RISK, OkrObjective::STATUS_OFF_TRACK]) as $row) {
            $items->push([
                'type' => 'OKR', 'label' => $row['objective_text'], 'detail' => $row['progress'] === null ? 'No key results yet' : round($row['progress'], 0).'% progress',
                'rail' => $row['status'] === OkrObjective::STATUS_OFF_TRACK ? 'danger' : 'warning', 'href' => $row['href'],
            ]);
        }

        return $items
            ->sortBy(fn (array $i) => $i['rail'] === 'danger' ? 0 : 1)
            ->values()
            ->all();
    }
}
