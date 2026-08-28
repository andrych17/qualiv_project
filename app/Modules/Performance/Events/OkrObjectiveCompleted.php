<?php

namespace App\Modules\Performance\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3I: fired only on the transition INTO 'completed' (previous status wasn't already
 * 'completed') — dispatched from OkrObjectiveService, never on a re-save of an already-completed
 * objective. Consumed by Listeners\AwardOkrCompletionAchievements.
 */
class OkrObjectiveCompleted
{
    use Dispatchable;

    public function __construct(public int $objectiveId) {}
}
