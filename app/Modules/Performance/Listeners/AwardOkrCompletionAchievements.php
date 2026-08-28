<?php

namespace App\Modules\Performance\Listeners;

use App\Modules\Performance\Events\OkrObjectiveCompleted;
use App\Modules\Performance\Services\AchievementService;

class AwardOkrCompletionAchievements
{
    public function __construct(protected AchievementService $achievements) {}

    public function handle(OkrObjectiveCompleted $event): void
    {
        $this->achievements->checkOkrCompleted($event->objectiveId);
    }
}
