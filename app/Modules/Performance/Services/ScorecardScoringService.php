<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Scorecard;
use App\Modules\Performance\Models\ScorecardItem;

/**
 * §3F Viewer — computes each item's actual/target/status/score "via the Variance Engine" (KPI
 * items, through VarianceService::evaluateKpi()) or the OKR progress calculator (OKR items,
 * through OkrProgressService), then rolls those into a per-perspective score and one overall
 * weighted score. Deliberately its own service rather than folded into VarianceService or
 * OkrProgressService — this is Scorecard-specific aggregation (grouping, weighting,
 * renormalizing over scored items), not a new metricRef type for either of those.
 *
 * An item with no data yet (no Target/KpiValue for a KPI, or zero Key Results for an OKR) is
 * excluded from BOTH the numerator and denominator of its perspective's weighted average, not
 * scored as 0 — the same failure §3H's budget-line-sum reasoning already flagged: folding
 * missing data into a weighted average as 0 would render a half-populated Scorecard as
 * confidently low. `scored_count`/`total_count` on every perspective (and overall) disclose how
 * much of the picture is actually populated, rather than hiding it.
 *
 * KPI items reuse VarianceResult's own actual/target/status directly — never re-querying the
 * Target/KpiValue separately for the score — so actual, target, and score can't drift apart
 * within the same row. OKR items use the Objective's own stored `status` (on_track/at_risk/
 * off_track/completed) rather than re-deriving a KPI-style on_track/warning/breach band from
 * its progress number — the Objective's status is already the tenant's authoritative read on
 * it (set via the Kanban board), and OKR/KPI status use different, non-interchangeable
 * vocabularies. Progress itself is deliberately uncapped everywhere except the numeric `score`
 * used in the weighted average, where it's clamped to [0, 100] — an Objective at 130% is real
 * information; a Scorecard's own 0–100 score is not.
 */
class ScorecardScoringService
{
    private const EPSILON = 0.00005;

    public function __construct(
        protected VarianceService $variance,
        protected OkrProgressService $okrProgress,
    ) {}

    /** @return array<string, mixed> */
    public function score(Scorecard $scorecard): array
    {
        $scorecard->loadMissing(['items.perspective', 'items.kpi', 'items.okr.keyResults']);

        $perspectives = [];
        $overallScoreSum = 0.0;
        $overallScoredCount = 0;

        foreach ($scorecard->items->groupBy('perspective_id') as $perspectiveId => $items) {
            $scoredItems = $items->map(fn (ScorecardItem $item) => [
                'item' => $item,
                'result' => $this->scoreItem($item, $scorecard),
            ]);

            $weightedSum = 0.0;
            $weightTotal = 0.0;
            $scoredCount = 0;

            foreach ($scoredItems as $entry) {
                if ($entry['result'] === null) {
                    continue;
                }

                $weight = (float) $entry['item']->weight;
                $weightedSum += $entry['result']['score'] * $weight;
                $weightTotal += $weight;
                $scoredCount++;
            }

            $perspectiveScore = $weightTotal > self::EPSILON ? $weightedSum / $weightTotal : null;

            $perspectives[] = [
                'perspective_id' => $perspectiveId,
                'perspective_name' => $items->first()->perspective?->name,
                'items' => $scoredItems->map(fn ($entry) => [
                    'id' => $entry['item']->id,
                    'label' => $entry['item']->kpi?->name ?? $entry['item']->okr?->objective_text ?? 'Unknown',
                    'type' => $entry['item']->kpi_id !== null ? 'kpi' : 'okr',
                    'weight' => (float) $entry['item']->weight,
                    'actual' => $entry['result']['actual'] ?? null,
                    'target' => $entry['result']['target'] ?? null,
                    'status' => $entry['result']['status'] ?? null,
                    'score' => $entry['result']['score'] ?? null,
                ])->all(),
                'score' => $perspectiveScore,
                'scored_count' => $scoredCount,
                'total_count' => $items->count(),
            ];

            if ($perspectiveScore !== null) {
                $overallScoreSum += $perspectiveScore;
                $overallScoredCount++;
            }
        }

        return [
            'perspectives' => $perspectives,
            // Equal-weight average across perspectives — no perspective-level weight field
            // exists in §3F (only item weight, scoped "per perspective"), so this is the only
            // well-defined reading of "a single overall weighted score for the subject+period."
            'overall_score' => $overallScoredCount > 0 ? $overallScoreSum / $overallScoredCount : null,
            'scored_perspectives' => $overallScoredCount,
            'total_perspectives' => count($perspectives),
        ];
    }

    /** @return array{actual: float, target: float, status: string, score: float}|null */
    private function scoreItem(ScorecardItem $item, Scorecard $scorecard): ?array
    {
        return $item->kpi_id !== null
            ? $this->scoreKpiItem($item, $scorecard)
            : $this->scoreOkrItem($item);
    }

    private function scoreKpiItem(ScorecardItem $item, Scorecard $scorecard): ?array
    {
        $result = $this->variance->evaluateKpi($scorecard->subject_type, $scorecard->subject_id, $item->kpi_id, $scorecard->period_id);
        if ($result === null) {
            return null;
        }

        return [
            'actual' => $result->actualValue,
            'target' => $result->planValue,
            'status' => $result->status,
            'score' => $this->achievementScore($result->planValue, $result->actualValue, $item->kpi->direction),
        ];
    }

    private function scoreOkrItem(ScorecardItem $item): ?array
    {
        $objective = $item->okr;
        $rawProgress = $this->okrProgress->objectiveProgress($objective);
        if ($rawProgress === null) {
            return null;
        }

        return [
            'actual' => $rawProgress,
            'target' => 100.0,
            'status' => $objective->status,
            'score' => min(max($rawProgress, 0.0), 100.0),
        ];
    }

    /** Direction-aware achievement ratio, clamped to [0, 100] for use in a weighted average. */
    private function achievementScore(float $target, float $actual, string $direction): float
    {
        $higherIsBetter = $direction === KpiDefinition::DIRECTION_HIGHER_IS_BETTER;

        if ($higherIsBetter) {
            if (abs($target) <= self::EPSILON) {
                return $actual >= 0 ? 100.0 : 0.0;
            }

            return min(max(($actual / $target) * 100, 0.0), 100.0);
        }

        if (abs($actual) <= self::EPSILON) {
            return 100.0; // zero (or negligible) actual against a lower-is-better metric is fully met
        }

        return min(max(($target / $actual) * 100, 0.0), 100.0);
    }
}
