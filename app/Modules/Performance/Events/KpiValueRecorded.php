<?php

namespace App\Modules\Performance\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3D: fired after KpiValueService::create()/update(). Consumed by
 * App\Modules\Performance\Listeners\EvaluateKpiValueVariance (§3G, registered in
 * AppServiceProvider), which re-evaluates this KPI/subject/period's status via VarianceService
 * and, on crossing into warning/breach, fires a NotificationRequested into WNE.
 */
class KpiValueRecorded
{
    use Dispatchable;

    public function __construct(
        public int $kpiId,
        public string $subjectType,
        public ?int $subjectId,
        public int $periodId,
    ) {}
}
