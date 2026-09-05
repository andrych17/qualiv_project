<?php

namespace Tests\Feature\Schedule;

use App\Models\User;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Models\SchedRecurrenceException;
use App\Modules\Schedule\Services\RecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpSchedule;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3F — RecurrenceService, exercised directly against the tenant DB. */
class ScheduleRecurrenceTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpSchedule;
    use SetsUpTenant;

    public function test_expand_rule_returns_the_expected_number_of_occurrences(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $anchor = Carbon::parse('2026-11-02 09:00:00');
            $service = app(RecurrenceService::class);

            $occurrences = $service->expandRule('FREQ=DAILY;COUNT=5', $anchor, $anchor->copy()->addHour(), $anchor->copy(), $anchor->copy()->addDays(30));

            $this->assertCount(5, $occurrences);
            $this->assertSame('2026-11-02', $occurrences->first()->start->toDateString());
            $this->assertSame('2026-11-06', $occurrences->last()->start->toDateString());
            $this->assertSame('scheduled', $occurrences->first()->status);
        });
    }

    public function test_expand_rule_carries_through_a_skipped_exception(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $owner = User::query()->first();
            $anchor = Carbon::parse('2026-11-02 09:00:00');
            $item = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Recurring', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => $anchor, 'end_at' => $anchor->copy()->addHour(),
                'recurrence_rule' => 'FREQ=DAILY;COUNT=5',
            ]);
            SchedRecurrenceException::query()->create([
                'sched_item_id' => $item->id, 'original_occurrence_date' => '2026-11-03',
                'action' => SchedRecurrenceException::ACTION_SKIPPED,
            ]);

            $occurrences = app(RecurrenceService::class)->expandItem($item, $anchor->copy(), $anchor->copy()->addDays(30));

            $skipped = $occurrences->firstWhere(fn ($occ) => $occ->originalDate->toDateString() === '2026-11-03');
            $this->assertSame('skipped', $skipped->status);
            // The base time is untouched by a skip — only the status changes.
            $this->assertSame('09:00:00', $skipped->start->format('H:i:s'));
        });
    }

    public function test_expand_rule_applies_a_moved_exceptions_override_times(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $owner = User::query()->first();
            $anchor = Carbon::parse('2026-11-02 09:00:00');
            $item = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Recurring', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => $anchor, 'end_at' => $anchor->copy()->addHour(),
                'recurrence_rule' => 'FREQ=DAILY;COUNT=5',
            ]);
            $override = Carbon::parse('2026-11-03 14:00:00');
            SchedRecurrenceException::query()->create([
                'sched_item_id' => $item->id, 'original_occurrence_date' => '2026-11-03',
                'action' => SchedRecurrenceException::ACTION_MOVED,
                'override_start_at' => $override, 'override_end_at' => $override->copy()->addHour(),
            ]);

            $occurrences = app(RecurrenceService::class)->expandItem($item, $anchor->copy(), $anchor->copy()->addDays(30));

            $moved = $occurrences->firstWhere(fn ($occ) => $occ->originalDate->toDateString() === '2026-11-03');
            $this->assertSame('moved', $moved->status);
            $this->assertSame('14:00:00', $moved->start->format('H:i:s'));
        });
    }

    public function test_expand_rule_is_capped_at_the_max_occurrences_safety_valve(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $anchor = Carbon::parse('2026-01-01 09:00:00');
            $service = app(RecurrenceService::class);

            // No COUNT= — unbounded until far in the future, well past 366 daily occurrences.
            $occurrences = $service->expandRule(
                'FREQ=DAILY;UNTIL=20301231T000000Z',
                $anchor,
                $anchor->copy()->addHour(),
                $anchor->copy(),
                $anchor->copy()->addYears(5),
            );

            $this->assertCount(366, $occurrences);
        });
    }

    public function test_expand_item_returns_empty_when_the_item_has_no_recurrence_rule(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $item = SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'One-off', 'owner_id' => User::query()->first()->id,
                'status' => 'open', 'due_at' => now()->addDay(),
            ]);

            $occurrences = app(RecurrenceService::class)->expandItem($item, now(), now()->addMonth());

            $this->assertCount(0, $occurrences);
        });
    }

    public function test_expand_item_anchors_on_due_at_for_a_task_and_start_end_for_an_event(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $owner = User::query()->first();

            $task = SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Recurring task', 'owner_id' => $owner->id,
                'status' => 'open', 'due_at' => Carbon::parse('2026-11-02 09:00:00'),
                'recurrence_rule' => 'FREQ=DAILY;COUNT=3',
            ]);
            $event = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Recurring event', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => Carbon::parse('2026-11-02 10:00:00'), 'end_at' => Carbon::parse('2026-11-02 11:00:00'),
                'recurrence_rule' => 'FREQ=DAILY;COUNT=3',
            ]);

            $service = app(RecurrenceService::class);
            $taskOccurrences = $service->expandItem($task, Carbon::parse('2026-11-01'), Carbon::parse('2026-12-01'));
            $eventOccurrences = $service->expandItem($event, Carbon::parse('2026-11-01'), Carbon::parse('2026-12-01'));

            $this->assertSame('09:00:00', $taskOccurrences->first()->start->format('H:i:s'));
            $this->assertSame('10:00:00', $eventOccurrences->first()->start->format('H:i:s'));
            $this->assertSame('11:00:00', $eventOccurrences->first()->end->format('H:i:s'));
        });
    }

    public function test_skip_reschedule_and_restore_occurrence_manage_the_exceptions_table(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $item = SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Recurring task', 'owner_id' => User::query()->first()->id,
                'status' => 'open', 'due_at' => Carbon::parse('2026-11-02 09:00:00'), 'recurrence_rule' => 'FREQ=DAILY;COUNT=5',
            ]);
            $service = app(RecurrenceService::class);
            $originalDate = Carbon::parse('2026-11-03');

            $service->skipOccurrence($item, $originalDate);
            $this->assertSame(
                SchedRecurrenceException::ACTION_SKIPPED,
                SchedRecurrenceException::query()->where('sched_item_id', $item->id)->value('action')
            );

            // Same-day reschedule -> "modified"; skipOccurrence's row is reused (updateOrCreate).
            $service->rescheduleOccurrence($item, $originalDate, $originalDate->copy()->setTime(15, 0), $originalDate->copy()->setTime(15, 30));
            $this->assertSame(
                SchedRecurrenceException::ACTION_MODIFIED,
                SchedRecurrenceException::query()->where('sched_item_id', $item->id)->value('action')
            );

            // Different-day reschedule -> "moved".
            $service->rescheduleOccurrence($item, $originalDate, $originalDate->copy()->addDay(), $originalDate->copy()->addDay());
            $this->assertSame(
                SchedRecurrenceException::ACTION_MOVED,
                SchedRecurrenceException::query()->where('sched_item_id', $item->id)->value('action')
            );

            $service->restoreOccurrence($item, $originalDate);
            $this->assertSame(0, SchedRecurrenceException::query()->where('sched_item_id', $item->id)->count());
        });
    }

    public function test_upcoming_occurrences_for_returns_empty_when_not_recurring(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $item = SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'One-off', 'owner_id' => User::query()->first()->id,
                'status' => 'open', 'due_at' => now()->addDay(),
            ]);

            $this->assertSame([], app(RecurrenceService::class)->upcomingOccurrencesFor($item));
        });
    }

    public function test_upcoming_occurrences_for_maps_within_window_and_respects_the_limit(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $item = SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Daily standup', 'owner_id' => User::query()->first()->id,
                'status' => 'open', 'due_at' => now()->addDay(), 'recurrence_rule' => 'FREQ=DAILY;COUNT=10',
            ]);

            $result = app(RecurrenceService::class)->upcomingOccurrencesFor($item, withinDays: 90, limit: 3);

            $this->assertCount(3, $result);
            $this->assertArrayHasKey('original_date', $result[0]);
            $this->assertArrayHasKey('start', $result[0]);
            $this->assertArrayHasKey('end', $result[0]);
            $this->assertArrayHasKey('status', $result[0]);
        });
    }
}
