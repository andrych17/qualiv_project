<?php

namespace App\Modules\WNE\Services;

use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use App\Modules\WNE\Models\WrkflowStep;
use Illuminate\Database\Eloquent\Builder;

/**
 * §3H — "what's waiting on me," resolved via direct assignment plus role membership.
 * Role membership reads SYSCONFIG.config_grpusers — WNE's one deliberate dependency on
 * SYSCONFIG, which is foundational plumbing every tenant has (CLAUDE.md §5), not an optional
 * Core module a "runs fully standalone" tenant might be missing (§1).
 */
class TaskInboxService
{
    /** Base query for every open, human-actionable step this user may act on — the single source both the inbox list and completeTask()'s authorization guard build on. */
    public function myTasksQuery(int $userId): Builder
    {
        return WrkflowInstanceStep::query()
            ->whereIn('status', [WrkflowInstanceStep::STATUS_PENDING, WrkflowInstanceStep::STATUS_IN_PROGRESS])
            ->whereHas('step', fn (Builder $q) => $q->whereIn('type', WrkflowStep::HUMAN_TYPES))
            ->whereHas('instance', fn (Builder $q) => $q->where('status', WrkflowInstance::STATUS_RUNNING))
            ->assignedToUser($userId, $this->groupCodesFor($userId));
    }

    /** Re-resolves from the DB (the passed-in row's own columns), never from anything a client sent — the authorization guard for WorkflowService::completeTask(). */
    public function isActionableBy(WrkflowInstanceStep $instanceStep, int $userId): bool
    {
        return $instanceStep->isActionableBy($userId, $this->groupCodesFor($userId));
    }

    private function groupCodesFor(int $userId): array
    {
        return ConfigGroupUser::query()->where('user_id', $userId)->pluck('group_code')->filter()->values()->all();
    }
}
