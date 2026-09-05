<?php

namespace Tests\Feature\Schedule;

use App\Models\User;
use App\Modules\Schedule\Models\ConferenceProvider;
use App\Modules\Schedule\Models\SchedAttendee;
use App\Modules\Schedule\Models\SchedBooking;
use App\Modules\Schedule\Models\SchedConferenceLink;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Models\SchedRecurrenceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpSchedule;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3A — ScheduleDashboardController + CalendarService's Status Rail logic. */
class ScheduleDashboardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpSchedule;
    use SetsUpTenant;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_index_defaults_to_month_view(): void
    {
        $this->loginAsScheduleAdmin();

        $this->get('/schedule/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Dashboard/Index')
                ->where('view', 'month'));
    }

    public function test_dashboard_index_supports_day_week_agenda_views_and_falls_back_for_an_unknown_view(): void
    {
        $this->loginAsScheduleAdmin();

        foreach (['day', 'week', 'agenda'] as $view) {
            $this->get("/schedule/dashboard?view={$view}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('view', $view));
        }

        $this->get('/schedule/dashboard?view=bogus')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('view', 'month'));
    }

    public function test_dashboard_filters_by_mine_owner_resource_and_subject_type(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $adminId = null;
        $otherOwnerId = null;
        $resourceId = null;
        $tenant->run(function () use (&$adminId, &$otherOwnerId, &$resourceId) {
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $otherOwnerId = User::factory()->create(['email' => 'other@nusaevo.com'])->id;
            $resourceId = $this->makeResource()->id;

            SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'My task', 'owner_id' => $adminId,
                'status' => 'open', 'due_at' => now()->addDay(),
            ]);
            SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Others task', 'owner_id' => $otherOwnerId,
                'status' => 'open', 'due_at' => now()->addDay(),
            ]);
            $linked = SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Legal-linked task', 'owner_id' => $adminId,
                'status' => 'open', 'due_at' => now()->addDay(), 'subject_type' => 'legal.case_hdrs', 'subject_id' => 1,
            ]);
            $withResource = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Booked event', 'owner_id' => $adminId,
                'status' => 'scheduled', 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
            ]);
            SchedBooking::query()->create(['sched_item_id' => $withResource->id, 'resource_id' => $resourceId]);
        });

        $this->get('/schedule/dashboard?view=agenda&mine=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.mine', true)
                ->where('items', fn ($items) => collect($items)->pluck('title')->contains('My task')
                    && ! collect($items)->pluck('title')->contains('Others task')));

        $this->get("/schedule/dashboard?view=agenda&owner_id={$otherOwnerId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items', fn ($items) => collect($items)->pluck('title')->contains('Others task')
                && ! collect($items)->pluck('title')->contains('My task')));

        $this->get("/schedule/dashboard?view=agenda&resource_id={$resourceId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items', fn ($items) => collect($items)->pluck('title')->contains('Booked event')
                && ! collect($items)->pluck('title')->contains('My task')));

        $this->get('/schedule/dashboard?view=agenda&subject_type=legal.case_hdrs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('subjectTypes', 1)
                ->where('items', fn ($items) => collect($items)->pluck('title')->contains('Legal-linked task')
                    && ! collect($items)->pluck('title')->contains('My task')));
    }

    public function test_status_rail_covers_overdue_due_soon_done_neutral_conflict_and_recurring(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00'));

        $tenant = $this->loginAsScheduleAdmin();

        $tenant->run(function () {
            $owner = User::query()->where('email', 'admin@nusaevo.com')->first();
            $resource = $this->makeResource();

            SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Overdue task', 'owner_id' => $owner->id,
                'status' => 'open', 'due_at' => Carbon::parse('2026-11-10 09:00:00'),
            ]);
            SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Due soon task', 'owner_id' => $owner->id,
                'status' => 'open', 'due_at' => Carbon::parse('2026-11-10 20:00:00'),
            ]);
            SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Future task', 'owner_id' => $owner->id,
                'status' => 'open', 'due_at' => Carbon::parse('2026-11-20 09:00:00'),
            ]);
            SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Done task', 'owner_id' => $owner->id,
                'status' => 'done', 'due_at' => Carbon::parse('2026-11-10 09:00:00'),
            ]);
            SchedItem::query()->create([
                // Would be "overdue" by time alone — cancelled status must suppress that.
                'type' => SchedItem::TYPE_TASK, 'title' => 'Cancelled overdue task', 'owner_id' => $owner->id,
                'status' => 'cancelled', 'due_at' => Carbon::parse('2026-11-10 09:00:00'),
            ]);

            // Two overlapping, non-cancelled bookings on the same resource — inserted
            // directly (bypassing EventService, which would reject the overlap) so the
            // dashboard's own conflict detection has something real to find.
            $eventA = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Conflicting event A', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => Carbon::parse('2026-11-12 10:00:00'), 'end_at' => Carbon::parse('2026-11-12 11:00:00'),
            ]);
            $eventB = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Conflicting event B', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => Carbon::parse('2026-11-12 10:30:00'), 'end_at' => Carbon::parse('2026-11-12 11:30:00'),
            ]);
            SchedBooking::query()->create(['sched_item_id' => $eventA->id, 'resource_id' => $resource->id]);
            SchedBooking::query()->create(['sched_item_id' => $eventB->id, 'resource_id' => $resource->id]);

            SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Plain event', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => Carbon::parse('2026-11-13 10:00:00'), 'end_at' => Carbon::parse('2026-11-13 11:00:00'),
            ]);

            $recurring = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Recurring instance', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => Carbon::parse('2026-11-11 09:00:00'), 'end_at' => Carbon::parse('2026-11-11 09:30:00'),
                'recurrence_rule' => 'FREQ=DAILY;COUNT=5',
            ]);
            // A skipped occurrence must be dropped from the calendar entirely, not just re-styled.
            SchedRecurrenceException::query()->create([
                'sched_item_id' => $recurring->id, 'original_occurrence_date' => '2026-11-12',
                'action' => SchedRecurrenceException::ACTION_SKIPPED,
            ]);
        });

        $response = $this->get('/schedule/dashboard?view=agenda&date=2026-11-10');
        $response->assertOk();

        $rawItems = collect($response->viewData('page')['props']['items']);
        $items = $rawItems->keyBy('title');

        $recurringOccurrenceDates = $rawItems->where('title', 'Recurring instance')->pluck('original_occurrence_date');
        $this->assertContains('2026-11-11', $recurringOccurrenceDates);
        $this->assertContains('2026-11-13', $recurringOccurrenceDates);
        $this->assertNotContains('2026-11-12', $recurringOccurrenceDates);

        $this->assertSame('danger', $items['Overdue task']['status_rail']);
        $this->assertSame('warning', $items['Due soon task']['status_rail']);
        $this->assertSame('neutral', $items['Future task']['status_rail']);
        $this->assertSame('success', $items['Done task']['status_rail']);
        $this->assertSame('danger', $items['Conflicting event A']['status_rail']);
        $this->assertSame('danger', $items['Conflicting event B']['status_rail']);
        $this->assertSame('neutral', $items['Plain event']['status_rail']);
        $this->assertSame('info', $items['Recurring instance']['status_rail']);
        $this->assertSame('neutral', $items['Cancelled overdue task']['status_rail']);
    }

    public function test_item_drawer_returns_task_and_event_shapes(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $taskId = null;
        $eventId = null;
        $tenant->run(function () use (&$taskId, &$eventId) {
            $this->makeConferenceProviders();
            $owner = User::query()->where('email', 'admin@nusaevo.com')->first();
            $attendee = User::factory()->create(['email' => 'drawer-attendee@nusaevo.com']);
            $resource = $this->makeResource();

            $taskId = SchedItem::query()->create([
                'type' => SchedItem::TYPE_TASK, 'title' => 'Drawer task', 'owner_id' => $owner->id,
                'status' => 'open', 'due_at' => now()->addDay(),
            ])->id;

            $event = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Drawer event', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
            ]);
            SchedBooking::query()->create(['sched_item_id' => $event->id, 'resource_id' => $resource->id]);
            SchedAttendee::query()->create([
                'sched_item_id' => $event->id, 'user_id' => $attendee->id, 'role' => 'attendee',
            ]);
            $provider = ConferenceProvider::query()->where('code', ConferenceProvider::CODE_MANUAL)->first();
            SchedConferenceLink::query()->create([
                'sched_item_id' => $event->id, 'conference_provider_id' => $provider->id, 'join_url' => 'https://example.com/join',
            ]);
            $eventId = $event->id;
        });

        $taskResponse = $this->get("/schedule/dashboard/item/{$taskId}")->assertOk()->json();
        $this->assertSame('task', $taskResponse['type']);
        $this->assertSame(route('schedule.tasks.edit', $taskId), $taskResponse['edit_url']);
        $this->assertSame(route('schedule.tasks.markDone', $taskId), $taskResponse['mark_done_url']);
        $this->assertSame(route('schedule.tasks.cancel', $taskId), $taskResponse['cancel_url']);

        $eventResponse = $this->get("/schedule/dashboard/item/{$eventId}")->assertOk()->json();
        $this->assertSame('event', $eventResponse['type']);
        $this->assertSame(route('schedule.events.edit', $eventId), $eventResponse['edit_url']);
        $this->assertNull($eventResponse['mark_done_url']);
        $this->assertSame(route('schedule.events.cancel', $eventId), $eventResponse['cancel_url']);
        $this->assertCount(1, $eventResponse['attendees']);
        $this->assertCount(1, $eventResponse['resources']);
        $this->assertSame('https://example.com/join', $eventResponse['conference_link']['join_url']);
    }

    public function test_quick_create_task_and_event_endpoints(): void
    {
        $this->loginAsScheduleAdmin();

        $this->post('/schedule/dashboard/quick-create-task', [
            'title' => 'Quick task',
            'due_at' => now()->addDay()->toDateTimeString(),
        ])->assertRedirect();
        $this->assertTrue(session()->has('success'));

        $this->post('/schedule/dashboard/quick-create-event', [
            'title' => 'Quick event',
            'start_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
        ])->assertRedirect();
        $this->assertTrue(session()->has('success'));
    }
}
