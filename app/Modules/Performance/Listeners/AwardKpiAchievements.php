<?php

namespace App\Modules\Performance\Listeners;

use App\Modules\Performance\Events\KpiValueRecorded;
use App\Modules\Performance\Services\AchievementService;

/**
 * §3I: second listener on KpiValueRecorded, registered alongside (not replacing) §3G's
 * EvaluateKpiValueVariance — Laravel stacks multiple listeners per event.
 */
class AwardKpiAchievements
{
    public function __construct(protected AchievementService $achievements) {}

    public function handle(KpiValueRecorded $event): void
    {
        $this->achievements->checkTargetHit($event->subjectType, $event->subjectId, $event->kpiId, $event->periodId);
        $this->achievements->checkStreakOnTrack($event->subjectType, $event->subjectId, $event->kpiId, $event->periodId);
    }
}
