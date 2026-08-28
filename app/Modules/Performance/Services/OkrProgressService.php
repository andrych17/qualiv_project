<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\OkrKeyResult;
use App\Modules\Performance\Models\OkrObjective;

/**
 * §3E: "Objective progress % = weighted average of its Key Results' progress — computed on
 * read, not stored." Deliberately separate from VarianceService (§3G) — OKR progress isn't a
 * plan-vs-actual comparison, it's a self-contained rollup of an Objective's own Key Results, and
 * §3G's own metricRef list (KPI+Target, Budget line, Forecast line) never mentions OKR.
 *
 * Returns null (never 0) when nothing can be computed — an Objective with zero Key Results, or
 * one where every Key Result's weight is zero — same "nothing to compare yet" convention
 * VarianceService's evaluate*() methods use, rather than reporting a misleadingly confident 0%.
 * Result is intentionally not clamped to [0, 100] — an Objective at 130% or -10% is real
 * information on the OKR board; clamping (where it's wanted, e.g. Scorecard scoring) belongs at
 * the consumption point, not baked into the shared calculator.
 */
class OkrProgressService
{
    private const EPSILON = 0.00005;

    public function objectiveProgress(OkrObjective $objective): ?float
    {
        $objective->loadMissing('keyResults');

        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($objective->keyResults as $keyResult) {
            $progress = $this->keyResultProgress($keyResult);
            $weight = (float) $keyResult->weight;

            if ($progress === null || $weight <= self::EPSILON) {
                continue;
            }

            $weightedSum += $progress * $weight;
            $weightTotal += $weight;
        }

        return $weightTotal <= self::EPSILON ? null : $weightedSum / $weightTotal;
    }

    /**
     * `boolean` is 100/0 on current_value truthiness. Every other metric type uses the same
     * direction-agnostic `(current - start) / (target - start)` — this correctly handles a
     * decreasing Key Result (e.g. churn 8% → 3%, target 2%) without needing a direction field,
     * since both the numerator and denominator flip sign together. Returns null when
     * target_value equals start_value (no meaningful ratio, e.g. a data-entry gap) rather than
     * dividing by zero or guessing.
     */
    public function keyResultProgress(OkrKeyResult $keyResult): ?float
    {
        if ($keyResult->metric_type === OkrKeyResult::METRIC_BOOLEAN) {
            return (float) $keyResult->current_value != 0.0 ? 100.0 : 0.0;
        }

        $range = (float) $keyResult->target_value - (float) $keyResult->start_value;
        if (abs($range) <= self::EPSILON) {
            return null;
        }

        return (((float) $keyResult->current_value - (float) $keyResult->start_value) / $range) * 100;
    }
}
