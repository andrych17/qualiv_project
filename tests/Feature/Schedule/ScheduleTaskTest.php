<?php

namespace Tests\Feature\Schedule;

use App\Models\User;
use App\Modules\Schedule\Models\SchedAttendee;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Models\SchedRecurrenceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpSchedule;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class ScheduleTaskTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpSchedule;
    use SetsUpTenant;

    public function test_admin_can_crud_a_task_with_watchers_and_quick_actions(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $watcherId = null;
        $tenant->run(function () use (&$watcherId) {
            $watcherId = User::factory()->create(['email' => 'watcher@nusaevo.com'])->id;
        });

        $this->get('/schedule/tasks')->assertOk()->assertInertia(fn ($page) => $page->component('Schedule/Tasks/Index'));
        $this->get('/schedule/tasks/create')->assertOk()->assertInertia(fn ($page) => $page->component('Schedule/Tasks/Create'));

        $this->post('/schedule/tasks', [
            'title' => 'Follow up with client',
            'due_at' => '2026-10-01 10:00:00',
            'priority' => 'high',
            'watcher_ids' => [$watcherId],
        ])->assertRedirect(route('schedule.tasks.index'));

        $taskId = null;
        $tenant->run(function () use (&$taskId) {
            $task = SchedItem::query()->where('title', 'Follow up with client')->first();
            $this->assertNotNull($task);
            $this->assertSame(SchedItem::TYPE_TASK, $task->type);
            $this->assertSame('open', $task->status);
            $this->assertSame(1, SchedAttendee::query()->where('sched_item_id', $task->id)->where('role', SchedAttendee::ROLE_WATCHER)->count());
            $taskId = $task->id;
        });

        $this->get("/schedule/tasks/{$taskId}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Tasks/Edit')
                ->where('task.title', 'Follow up with client')
                ->has('task.watcher_ids', 1));

        $newWatcherId = null;
        $tenant->run(function () use (&$newWatcherId) {
            $newWatcherId = User::factory()->create(['email' => 'watcher2@nusaevo.com'])->id;
        });

        $this->put("/schedule/tasks/{$taskId}", [
            'title' => 'Follow up with client (updated)',
            'due_at' => '2026-10-02 10:00:00',
            'priority' => 'normal',
            'status' => 'in_progress',
            'watcher_ids' => [$newWatcherId],
        ])->assertRedirect(route('schedule.tasks.index'));

        $tenant->run(function () use ($taskId, $watcherId, $newWatcherId) {
            $task = SchedItem::query()->find($taskId);
            $this->assertSame('in_progress', $task->status);
            $this->assertSame('Follow up with client (updated)', $task->title);
            $this->assertSame(0, SchedAttendee::query()->where('sched_item_id', $taskId)->where('user_id', $watcherId)->count());
            $this->assertSame(1, SchedAttendee::query()->where('sched_item_id', $taskId)->where('user_id', $newWatcherId)->count());
        });

        $this->post("/schedule/tasks/{$taskId}/mark-done")->assertRedirect();
        $tenant->run(function () use ($taskId) {
            $this->assertSame('done', SchedItem::query()->find($taskId)->status);
        });

        $this->post("/schedule/tasks/{$taskId}/cancel")->assertRedirect();
        $tenant->run(function () use ($taskId) {
            $this->assertSame('cancelled', SchedItem::query()->find($taskId)->status);
        });

        $this->delete("/schedule/tasks/{$taskId}")->assertRedirect(route('schedule.tasks.index'));
        $tenant->run(function () use ($taskId) {
            $this->assertNull(SchedItem::query()->find($taskId));
            $this->assertSame(0, SchedAttendee::query()->where('sched_item_id', $taskId)->count());
        });
    }

    public function test_task_index_filters_by_search_status_priority_owner_and_sort(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $ownerId = null;
        $tenant->run(function () use (&$ownerId) {
            $owner = User::factory()->create(['email' => 'owner2@nusaevo.com']);
            $ownerId = $owner->id;

            SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Alpha task', 'owner_id' => $owner->id,
                'status' => 'open', 'priority' => 'low', 'due_at' => now()->addDay(),
            ]);
            SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Beta task', 'owner_id' => $owner->id,
                'status' => 'done', 'priority' => 'high', 'due_at' => now()->addDays(2),
            ]);
        });

        $this->get('/schedule/tasks?search=Alpha')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tasks.data', 1));

        $this->get('/schedule/tasks?status=done')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tasks.data', 1)->where('tasks.data.0.title', 'Beta task'));

        $this->get('/schedule/tasks?priority=low')->assertOk()
            ->assertInertia(fn ($page) => $page->has('tasks.data', 1)->where('tasks.data.0.title', 'Alpha task'));

        $this->get("/schedule/tasks?owner_id={$ownerId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('tasks.data', 2));

        $this->get('/schedule/tasks?sort=title&direction=desc&per_page=5')->assertOk()
            ->assertInertia(fn ($page) => $page->where('tasks.data.0.title', 'Beta task'));
    }

    public function test_task_occurrence_skip_reschedule_and_restore(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $taskId = null;
        $tenant->run(function () use (&$taskId) {
            $task = SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Weekly review',
                'owner_id' => User::query()->first()->id, 'status' => 'open',
                'due_at' => Carbon::parse('2026-10-05 09:00:00'), 'recurrence_rule' => 'FREQ=WEEKLY;COUNT=5',
            ]);
            $taskId = $task->id;
        });

        $this->post("/schedule/tasks/{$taskId}/occurrences/skip", [
            'original_occurrence_date' => '2026-10-12',
        ])->assertRedirect();

        $tenant->run(function () use ($taskId) {
            $this->assertSame(
                SchedRecurrenceException::ACTION_SKIPPED,
                SchedRecurrenceException::query()->where('sched_item_id', $taskId)->where('original_occurrence_date', '2026-10-12')->value('action')
            );
        });

        // Same day -> "modified"; different day -> "moved".
        $this->post("/schedule/tasks/{$taskId}/occurrences/reschedule", [
            'original_occurrence_date' => '2026-10-19',
            'start_at' => '2026-10-19 14:00:00',
        ])->assertRedirect();

        $tenant->run(function () use ($taskId) {
            $this->assertSame(
                SchedRecurrenceException::ACTION_MODIFIED,
                SchedRecurrenceException::query()->where('sched_item_id', $taskId)->where('original_occurrence_date', '2026-10-19')->value('action')
            );
        });

        $this->post("/schedule/tasks/{$taskId}/occurrences/reschedule", [
            'original_occurrence_date' => '2026-10-26',
            'start_at' => '2026-10-28 09:00:00',
        ])->assertRedirect();

        $tenant->run(function () use ($taskId) {
            $this->assertSame(
                SchedRecurrenceException::ACTION_MOVED,
                SchedRecurrenceException::query()->where('sched_item_id', $taskId)->where('original_occurrence_date', '2026-10-26')->value('action')
            );
        });

        $this->post("/schedule/tasks/{$taskId}/occurrences/restore", [
            'original_occurrence_date' => '2026-10-12',
        ])->assertRedirect();

        $tenant->run(function () use ($taskId) {
            $this->assertNull(
                SchedRecurrenceException::query()->where('sched_item_id', $taskId)->where('original_occurrence_date', '2026-10-12')->first()
            );
        });
    }

    public function test_store_task_validation_rejects_missing_fields_bad_priority_and_unbounded_recurrence(): void
    {
        $this->loginAsScheduleAdmin();

        $this->post('/schedule/tasks', [])->assertSessionHasErrors(['title', 'due_at']);

        $this->post('/schedule/tasks', [
            'title' => 'Bad priority',
            'due_at' => now()->addDay()->toDateTimeString(),
            'priority' => 'urgent',
        ])->assertSessionHasErrors(['priority']);

        $this->post('/schedule/tasks', [
            'title' => 'Unbounded recurrence',
            'due_at' => now()->addDay()->toDateTimeString(),
            'recurrence_rule' => 'FREQ=DAILY',
        ])->assertSessionHasErrors(['recurrence_rule']);

        $this->post('/schedule/tasks', [
            'title' => 'Too many occurrences',
            'due_at' => now()->addDay()->toDateTimeString(),
            'recurrence_rule' => 'FREQ=DAILY;COUNT=400',
        ])->assertSessionHasErrors(['recurrence_rule']);

        $this->post('/schedule/tasks', [
            'title' => 'Invalid rule syntax',
            'due_at' => now()->addDay()->toDateTimeString(),
            'recurrence_rule' => 'FREQ=NOTAFREQ;COUNT=5',
        ])->assertSessionHasErrors(['recurrence_rule']);
    }
}
