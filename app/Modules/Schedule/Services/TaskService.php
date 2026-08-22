<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Models\SchedAttendee;
use App\Modules\Schedule\Models\SchedItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** §3B — Task Management: single-owner to-dos, optional watchers. */
class TaskService
{
    public function __construct(
        protected RecurrenceService $recurrence,
    ) {}

    public function create(array $data, int $actorId): SchedItem
    {
        return DB::transaction(function () use ($data, $actorId) {
            $task = SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'owner_id' => $data['owner_id'] ?? $actorId,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'recurrence_rule' => $data['recurrence_rule'] ?? null,
                'status' => 'open',
                'priority' => $data['priority'] ?? null,
                'due_at' => $data['due_at'],
            ]);

            $this->syncWatchers($task, $data['watcher_ids'] ?? []);

            return $task;
        });
    }

    public function update(SchedItem $task, array $data): SchedItem
    {
        return DB::transaction(function () use ($task, $data) {
            $task->update([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'owner_id' => $data['owner_id'] ?? $task->owner_id,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'recurrence_rule' => $data['recurrence_rule'] ?? null,
                'status' => $data['status'],
                'priority' => $data['priority'] ?? null,
                'due_at' => $data['due_at'],
            ]);

            $this->syncWatchers($task, $data['watcher_ids'] ?? []);

            return $task->refresh();
        });
    }

    /** §3B: "Mark Done" — one-click from both the dashboard and the item drawer. */
    public function markDone(SchedItem $task): SchedItem
    {
        $task->update(['status' => 'done']);

        return $task;
    }

    /** §3A: drawer quick action — one-click cancel, alternative to opening the full edit form. */
    public function cancel(SchedItem $task): SchedItem
    {
        $task->update(['status' => 'cancelled']);

        return $task;
    }

    public function delete(SchedItem $task): void
    {
        DB::transaction(function () use ($task) {
            $task->attendees()->delete();
            $task->recurrenceExceptions()->delete();
            $task->delete();
        });
    }

    /** §3F: tasks book no resources, so no availability check is needed here. */
    public function skipOccurrence(SchedItem $task, Carbon $originalDate): void
    {
        $this->recurrence->skipOccurrence($task, $originalDate);
    }

    public function rescheduleOccurrence(SchedItem $task, Carbon $originalDate, Carbon $newDueAt): void
    {
        $this->recurrence->rescheduleOccurrence($task, $originalDate, $newDueAt, $newDueAt);
    }

    public function restoreOccurrence(SchedItem $task, Carbon $originalDate): void
    {
        $this->recurrence->restoreOccurrence($task, $originalDate);
    }

    /** @param  list<int>  $userIds */
    private function syncWatchers(SchedItem $task, array $userIds): void
    {
        SchedAttendee::query()->where('sched_item_id', $task->id)->delete();

        foreach (array_unique($userIds) as $userId) {
            SchedAttendee::query()->create([
                'sched_item_id' => $task->id,
                'user_id' => $userId,
                'role' => SchedAttendee::ROLE_WATCHER,
            ]);
        }
    }
}
