<?php

namespace App\Modules\Performance\Listeners;

use App\Modules\Performance\Data\VarianceResult;
use App\Modules\Performance\Events\KpiValueRecorded;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Services\VarianceService;
use App\Modules\WNE\Events\NotificationRequested;

/**
 * §3D/§3G: "fires KpiValueRecorded → Variance Engine re-evaluates status → if it crosses into
 * warning/breach, fires a NotificationRequested into WNE" — the listener that finally closes
 * the gap KpiValueService's docblock flagged (event previously had no consumer).
 *
 * MVP simplification, flagged deliberately: "crosses into" would ideally mean a state
 * transition (was on_track, now isn't), but nothing in §3C/§3D persists a KPI/subject/period's
 * *previous* status to compare against — Scorecard (§3F), the natural home for that, isn't
 * built either. So this fires every time a save lands the KPI in warning/breach, not only on
 * the first crossing. Acceptable at MVP volumes (§3G: "no queue needed at MVP data volumes");
 * revisit if repeat notifications on an already-breached KPI prove noisy in practice.
 */
class EvaluateKpiValueVariance
{
    public function __construct(protected VarianceService $variance) {}

    public function handle(KpiValueRecorded $event): void
    {
        $result = $this->variance->evaluateKpi($event->subjectType, $event->subjectId, $event->kpiId, $event->periodId);

        if ($result === null || $result->status === VarianceResult::STATUS_ON_TRACK) {
            return;
        }

        $kpi = KpiDefinition::query()->find($event->kpiId);
        $period = Period::query()->find($event->periodId);

        NotificationRequested::dispatch(
            category: 'performance.variance_breach',
            recipient: ['type' => 'role', 'role' => 'ADMIN'],
            payload: [
                'kpi_id' => $event->kpiId,
                'subject_type' => $event->subjectType,
                'subject_id' => $event->subjectId,
                'period_id' => $event->periodId,
                'status' => $result->status,
                'plan_value' => $result->planValue,
                'actual_value' => $result->actualValue,
                'variance_pct' => $result->variancePct,
            ],
            subjectType: 'performance.kpi_definitions',
            subjectId: $event->kpiId,
            subject: "KPI {$result->status}: ".($kpi?->name ?? "#{$event->kpiId}"),
            body: "{$kpi?->name} is {$result->status} for {$period?->label}: actual {$result->actualValue} vs. target {$result->planValue}.",
        );
    }
}
