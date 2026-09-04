<?php

namespace Tests\Feature\Schedule;

use App\Models\User;
use App\Modules\Schedule\Models\ConferenceProvider;
use App\Modules\Schedule\Models\SchedAttendee;
use App\Modules\Schedule\Models\SchedBooking;
use App\Modules\Schedule\Models\SchedConferenceLink;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Models\SchedRecurrenceException;
use App\Modules\Schedule\Models\SchedWorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SetsUpSchedule;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class ScheduleEventTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpSchedule;
    use SetsUpTenant;

    public function test_admin_can_crud_an_event_with_attendees_resources_and_manual_conference_link(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $resourceId = null;
        $attendeeId = null;
        $tenant->run(function () use (&$resourceId, &$attendeeId) {
            $this->makeConferenceProviders();
            $resourceId = $this->makeResource()->id;
            $attendeeId = User::factory()->create(['email' => 'attendee@nusaevo.com'])->id;
        });

        $this->get('/schedule/events')->assertOk()->assertInertia(fn ($page) => $page->component('Schedule/Events/Index'));
        $this->get('/schedule/events/create')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Schedule/Events/Create')
            ->has('resources', 1)
            ->has('conferenceProviders', 2));

        $this->post('/schedule/events', [
            'title' => 'Client kickoff',
            'start_at' => '2026-11-02 10:00:00',
            'end_at' => '2026-11-02 11:00:00',
            'location' => 'HQ',
            'attendee_ids' => [$attendeeId],
            'resource_ids' => [$resourceId],
            'conference_provider_code' => ConferenceProvider::CODE_MANUAL,
            'conference_manual_url' => 'https://meet.example.com/kickoff',
        ])->assertRedirect(route('schedule.events.index'));

        $eventId = null;
        $tenant->run(function () use (&$eventId, $resourceId, $attendeeId) {
            $event = SchedItem::query()->where('title', 'Client kickoff')->first();
            $this->assertNotNull($event);
            $this->assertSame(SchedItem::TYPE_EVENT, $event->type);
            $this->assertSame('scheduled', $event->status);
            $this->assertSame(1, SchedBooking::query()->where('sched_item_id', $event->id)->where('resource_id', $resourceId)->count());
            $this->assertSame(1, SchedAttendee::query()->where('sched_item_id', $event->id)->where('user_id', $attendeeId)->where('role', SchedAttendee::ROLE_ATTENDEE)->count());
            $link = SchedConferenceLink::query()->where('sched_item_id', $event->id)->first();
            $this->assertNotNull($link);
            $this->assertSame('https://meet.example.com/kickoff', $link->join_url);
            $eventId = $event->id;
        });

        $this->get("/schedule/events/{$eventId}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Events/Edit')
                ->where('event.title', 'Client kickoff')
                ->where('event.conference_link.provider_code', ConferenceProvider::CODE_MANUAL)
                ->has('event.resource_ids', 1)
                ->has('event.attendee_ids', 1));

        $resource2Id = null;
        $tenant->run(function () use (&$resource2Id) {
            $resource2Id = $this->makeResource(null, 'Room B')->id;
        });

        // Update: swap resources, drop attendees, remove the conference link.
        $this->put("/schedule/events/{$eventId}", [
            'title' => 'Client kickoff (updated)',
            'start_at' => '2026-11-02 13:00:00',
            'end_at' => '2026-11-02 14:00:00',
            'status' => 'scheduled',
            'resource_ids' => [$resource2Id],
            'conference_remove' => true,
        ])->assertRedirect(route('schedule.events.index'));

        $tenant->run(function () use ($eventId, $resourceId, $resource2Id) {
            $event = SchedItem::query()->find($eventId);
            $this->assertSame('Client kickoff (updated)', $event->title);
            $this->assertSame(0, SchedBooking::query()->where('sched_item_id', $eventId)->where('resource_id', $resourceId)->count());
            $this->assertSame(1, SchedBooking::query()->where('sched_item_id', $eventId)->where('resource_id', $resource2Id)->count());
            $this->assertSame(0, SchedAttendee::query()->where('sched_item_id', $eventId)->count());
            $this->assertNull(SchedConferenceLink::query()->where('sched_item_id', $eventId)->first());
        });

        $this->post("/schedule/events/{$eventId}/cancel")->assertRedirect();
        $tenant->run(function () use ($eventId) {
            $this->assertSame('cancelled', SchedItem::query()->find($eventId)->status);
        });

        $this->delete("/schedule/events/{$eventId}")->assertRedirect(route('schedule.events.index'));
        $tenant->run(function () use ($eventId) {
            $this->assertNull(SchedItem::query()->find($eventId));
            $this->assertSame(0, SchedBooking::query()->where('sched_item_id', $eventId)->count());
        });
    }

    public function test_event_index_filters_by_search_status_owner_and_sort(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $ownerId = null;
        $tenant->run(function () use (&$ownerId) {
            $owner = User::factory()->create(['email' => 'evtowner@nusaevo.com']);
            $ownerId = $owner->id;
            SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Alpha sync', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
            ]);
            SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Beta review', 'owner_id' => $owner->id,
                'status' => 'cancelled', 'start_at' => now()->addDays(2), 'end_at' => now()->addDays(2)->addHour(),
            ]);
        });

        $this->get('/schedule/events?search=Alpha')->assertOk()
            ->assertInertia(fn ($page) => $page->has('events.data', 1));

        $this->get('/schedule/events?status=cancelled')->assertOk()
            ->assertInertia(fn ($page) => $page->has('events.data', 1)->where('events.data.0.title', 'Beta review'));

        $this->get("/schedule/events?owner_id={$ownerId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('events.data', 2));

        $this->get('/schedule/events?sort=title&direction=desc&per_page=5')->assertOk()
            ->assertInertia(fn ($page) => $page->where('events.data.0.title', 'Beta review'));
    }

    public function test_store_event_rejects_double_booking_the_same_resource(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $resourceId = null;
        $tenant->run(function () use (&$resourceId) {
            $resourceId = $this->makeResource(null, 'Room A')->id;
        });

        $this->post('/schedule/events', [
            'title' => 'First booking',
            'start_at' => '2026-11-10 10:00:00',
            'end_at' => '2026-11-10 11:00:00',
            'resource_ids' => [$resourceId],
        ])->assertRedirect(route('schedule.events.index'));

        $response = $this->post('/schedule/events', [
            'title' => 'Overlapping booking',
            'start_at' => '2026-11-10 10:30:00',
            'end_at' => '2026-11-10 11:30:00',
            'resource_ids' => [$resourceId],
        ]);

        $response->assertSessionHasErrors(['resource_ids']);
        $tenant->run(function () {
            $this->assertNull(SchedItem::query()->where('title', 'Overlapping booking')->first());
        });
    }

    public function test_store_event_rejects_booking_outside_resource_working_hours(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $resourceId = null;
        $tenant->run(function () use (&$resourceId) {
            $resource = $this->makeResource(null, 'Restricted Room');
            SchedWorkingHour::query()->create(['resource_id' => $resource->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);
            $resourceId = $resource->id;
        });

        // Sunday (day_of_week 0) has no working-hours row for this resource.
        $sunday = Carbon::parse('next sunday')->setTime(10, 0);

        $response = $this->post('/schedule/events', [
            'title' => 'Sunday meeting',
            'start_at' => $sunday->toDateTimeString(),
            'end_at' => $sunday->copy()->addHour()->toDateTimeString(),
            'resource_ids' => [$resourceId],
        ]);

        $response->assertSessionHasErrors(['resource_ids']);

        $monday = Carbon::parse('next monday')->setTime(10, 0);

        $this->post('/schedule/events', [
            'title' => 'Monday meeting',
            'start_at' => $monday->toDateTimeString(),
            'end_at' => $monday->copy()->addHour()->toDateTimeString(),
            'resource_ids' => [$resourceId],
        ])->assertRedirect(route('schedule.events.index'));

        $tenant->run(function () {
            $this->assertNotNull(SchedItem::query()->where('title', 'Monday meeting')->first());
        });
    }

    public function test_recurring_event_availability_is_checked_per_occurrence(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $resourceId = null;
        $tenant->run(function () use (&$resourceId) {
            $resourceId = $this->makeResource(null, 'Weekly Room')->id;
        });

        $monday = Carbon::parse('next monday')->setTime(9, 0);

        $this->post('/schedule/events', [
            'title' => 'Weekly standup',
            'start_at' => $monday->toDateTimeString(),
            'end_at' => $monday->copy()->addMinutes(30)->toDateTimeString(),
            'resource_ids' => [$resourceId],
            'recurrence_rule' => 'FREQ=WEEKLY;COUNT=4',
        ])->assertRedirect(route('schedule.events.index'));

        // Third occurrence (two weeks later) overlaps the first series even though
        // its own base start_at differs — the per-occurrence expansion must catch it.
        $thirdWeek = $monday->copy()->addWeeks(2);

        $response = $this->post('/schedule/events', [
            'title' => 'Conflicting one-off',
            'start_at' => $thirdWeek->toDateTimeString(),
            'end_at' => $thirdWeek->copy()->addMinutes(30)->toDateTimeString(),
            'resource_ids' => [$resourceId],
        ]);

        $response->assertSessionHasErrors(['resource_ids']);
    }

    public function test_event_occurrence_skip_reschedule_and_restore_re_checks_availability(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $resourceId = null;
        $eventId = null;
        $blockerStart = null;
        $originalDate = null;
        $tenant->run(function () use (&$resourceId, &$eventId, &$blockerStart, &$originalDate) {
            $resourceId = $this->makeResource(null, 'Occurrence Room')->id;
            $owner = User::query()->first();

            $monday = Carbon::parse('next monday')->setTime(9, 0);
            $originalDate = $monday->copy()->addWeek(); // second occurrence

            $event = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Recurring sync', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => $monday, 'end_at' => $monday->copy()->addHour(),
                'recurrence_rule' => 'FREQ=WEEKLY;COUNT=4',
            ]);
            SchedBooking::query()->create(['sched_item_id' => $event->id, 'resource_id' => $resourceId]);
            $eventId = $event->id;

            $blockerStart = $monday->copy()->addWeeks(3)->addHours(3);
            $blocker = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Blocker', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => $blockerStart, 'end_at' => $blockerStart->copy()->addHour(),
            ]);
            SchedBooking::query()->create(['sched_item_id' => $blocker->id, 'resource_id' => $resourceId]);
        });

        $this->post("/schedule/events/{$eventId}/occurrences/skip", [
            'original_occurrence_date' => $originalDate->toDateString(),
        ])->assertRedirect();

        $tenant->run(function () use ($eventId, $originalDate) {
            $this->assertSame(
                SchedRecurrenceException::ACTION_SKIPPED,
                SchedRecurrenceException::query()->where('sched_item_id', $eventId)->where('original_occurrence_date', $originalDate->toDateString())->value('action')
            );
        });

        // Reschedule that same occurrence into the blocker's window -> rejected, no exception row overwritten.
        $conflictResponse = $this->post("/schedule/events/{$eventId}/occurrences/reschedule", [
            'original_occurrence_date' => $originalDate->toDateString(),
            'start_at' => $blockerStart->toDateTimeString(),
            'end_at' => $blockerStart->copy()->addHour()->toDateTimeString(),
        ]);
        $conflictResponse->assertSessionHasErrors();

        // Reschedule into a free slot on a different day succeeds (-> "moved").
        $freeSlot = $originalDate->copy()->addDay()->setTime(9, 0);
        $this->post("/schedule/events/{$eventId}/occurrences/reschedule", [
            'original_occurrence_date' => $originalDate->toDateString(),
            'start_at' => $freeSlot->toDateTimeString(),
            'end_at' => $freeSlot->copy()->addHour()->toDateTimeString(),
        ])->assertRedirect();

        $tenant->run(function () use ($eventId, $originalDate) {
            $this->assertSame(
                SchedRecurrenceException::ACTION_MOVED,
                SchedRecurrenceException::query()->where('sched_item_id', $eventId)->where('original_occurrence_date', $originalDate->toDateString())->value('action')
            );
        });

        $this->post("/schedule/events/{$eventId}/occurrences/restore", [
            'original_occurrence_date' => $originalDate->toDateString(),
        ])->assertRedirect();

        $tenant->run(function () use ($eventId, $originalDate) {
            $this->assertNull(
                SchedRecurrenceException::query()->where('sched_item_id', $eventId)->where('original_occurrence_date', $originalDate->toDateString())->first()
            );
        });
    }

    public function test_zoom_conference_link_is_created_via_http_and_removed_on_update(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok123'], 200),
            'api.zoom.us/v2/users/me/meetings' => Http::response([
                'id' => 987654321,
                'join_url' => 'https://zoom.us/j/987654321',
                'settings' => ['global_dial_in_numbers' => [['number' => '+1-555-0100']]],
            ], 201),
            'api.zoom.us/v2/meetings/*' => Http::response([], 204),
        ]);

        $tenant = $this->loginAsScheduleAdmin();

        $tenant->run(function () {
            $this->makeConferenceProviders();
        });

        $this->post('/schedule/events', [
            'title' => 'Zoom call',
            'start_at' => '2026-12-01 10:00:00',
            'end_at' => '2026-12-01 11:00:00',
            'conference_provider_code' => ConferenceProvider::CODE_ZOOM,
        ])->assertRedirect(route('schedule.events.index'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.zoom.us/v2/users/me/meetings'));

        $eventId = null;
        $tenant->run(function () use (&$eventId) {
            $event = SchedItem::query()->where('title', 'Zoom call')->first();
            $link = SchedConferenceLink::query()->where('sched_item_id', $event->id)->first();
            $this->assertNotNull($link);
            $this->assertSame('https://zoom.us/j/987654321', $link->join_url);
            $this->assertSame('987654321', $link->external_meeting_id);
            $this->assertSame('+1-555-0100', $link->dial_in_info);
            $eventId = $event->id;
        });

        $this->put("/schedule/events/{$eventId}", [
            'title' => 'Zoom call',
            'start_at' => '2026-12-01 10:00:00',
            'end_at' => '2026-12-01 11:00:00',
            'status' => 'scheduled',
            'conference_remove' => true,
        ])->assertRedirect(route('schedule.events.index'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.zoom.us/v2/meetings/987654321'));

        $tenant->run(function () use ($eventId) {
            $this->assertNull(SchedConferenceLink::query()->where('sched_item_id', $eventId)->first());
        });
    }

    public function test_store_event_validation_rejects_bad_fields_and_invalid_references(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $tenant->run(function () {
            $this->makeConferenceProviders();
        });

        $this->post('/schedule/events', [])->assertSessionHasErrors(['title', 'start_at', 'end_at']);

        $this->post('/schedule/events', [
            'title' => 'Backwards times',
            'start_at' => '2026-12-05 11:00:00',
            'end_at' => '2026-12-05 10:00:00',
        ])->assertSessionHasErrors(['end_at']);

        $this->post('/schedule/events', [
            'title' => 'Bad resource',
            'start_at' => '2026-12-05 10:00:00',
            'end_at' => '2026-12-05 11:00:00',
            'resource_ids' => [999999],
        ])->assertSessionHasErrors(['resource_ids']);

        $this->post('/schedule/events', [
            'title' => 'Bad provider',
            'start_at' => '2026-12-05 10:00:00',
            'end_at' => '2026-12-05 11:00:00',
            'conference_provider_code' => 'not_a_real_provider',
        ])->assertSessionHasErrors(['conference_provider_code']);

        $this->post('/schedule/events', [
            'title' => 'Manual link missing url',
            'start_at' => '2026-12-05 10:00:00',
            'end_at' => '2026-12-05 11:00:00',
            'conference_provider_code' => ConferenceProvider::CODE_MANUAL,
        ])->assertSessionHasErrors(['conference_manual_url']);

        $this->post('/schedule/events', [
            'title' => 'Unbounded recurrence',
            'start_at' => '2026-12-05 10:00:00',
            'end_at' => '2026-12-05 11:00:00',
            'recurrence_rule' => 'FREQ=DAILY',
        ])->assertSessionHasErrors(['recurrence_rule']);
    }

    public function test_update_event_validation_rejects_bad_status_and_invalid_references(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $eventId = null;
        $tenant->run(function () use (&$eventId) {
            $eventId = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Editable event', 'owner_id' => User::query()->first()->id,
                'status' => 'scheduled', 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
            ])->id;
        });

        $this->put("/schedule/events/{$eventId}", [
            'title' => 'Editable event',
            'start_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
            'status' => 'archived',
        ])->assertSessionHasErrors(['status']);

        $this->put("/schedule/events/{$eventId}", [
            'title' => 'Editable event',
            'start_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
            'status' => 'scheduled',
            'resource_ids' => [999999],
        ])->assertSessionHasErrors(['resource_ids']);

        $this->put("/schedule/events/{$eventId}", [
            'title' => 'Editable event',
            'start_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHour()->toDateTimeString(),
            'status' => 'scheduled',
            'conference_provider_code' => 'not_a_real_provider',
        ])->assertSessionHasErrors(['conference_provider_code']);
    }

    public function test_deleting_an_event_without_a_conference_link_is_a_no_op_detach(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $eventId = null;
        $tenant->run(function () use (&$eventId) {
            $eventId = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'No conference', 'owner_id' => User::query()->first()->id,
                'status' => 'scheduled', 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
            ])->id;
        });

        $this->delete("/schedule/events/{$eventId}")->assertRedirect(route('schedule.events.index'));

        $tenant->run(function () use ($eventId) {
            $this->assertNull(SchedItem::query()->find($eventId));
        });
    }
}
