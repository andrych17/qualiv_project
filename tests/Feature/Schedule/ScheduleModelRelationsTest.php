<?php

namespace Tests\Feature\Schedule;

use App\Models\User;
use App\Modules\Schedule\Models\ConferenceProvider;
use App\Modules\Schedule\Models\Resource;
use App\Modules\Schedule\Models\ResourceType;
use App\Modules\Schedule\Models\SchedAttendee;
use App\Modules\Schedule\Models\SchedBooking;
use App\Modules\Schedule\Models\SchedConferenceLink;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Models\SchedRecurrenceException;
use App\Modules\Schedule\Models\SchedWorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpSchedule;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * The inverse side of several Schedule relations (child -> parent SchedItem,
 * ResourceType -> Resource, Resource -> bookings) is never navigated by app
 * code — every controller/service only ever walks parent -> children. Covered
 * here directly so the relation methods themselves are exercised.
 */
class ScheduleModelRelationsTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpSchedule;
    use SetsUpTenant;

    public function test_inverse_relations_resolve_to_their_owning_records(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $owner = User::query()->first();
            $type = $this->makeResourceType();
            $resource = $this->makeResource($type);
            $this->makeConferenceProviders();
            $provider = ConferenceProvider::query()->where('code', ConferenceProvider::CODE_MANUAL)->first();

            $item = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT, 'title' => 'Relations event', 'owner_id' => $owner->id,
                'status' => 'scheduled', 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
                'recurrence_rule' => 'FREQ=DAILY;COUNT=3',
            ]);

            $booking = SchedBooking::query()->create(['sched_item_id' => $item->id, 'resource_id' => $resource->id]);
            $attendee = SchedAttendee::query()->create(['sched_item_id' => $item->id, 'user_id' => $owner->id, 'role' => SchedAttendee::ROLE_ATTENDEE]);
            $link = SchedConferenceLink::query()->create([
                'sched_item_id' => $item->id, 'conference_provider_id' => $provider->id, 'join_url' => 'https://example.com/x',
            ]);
            $exception = SchedRecurrenceException::query()->create([
                'sched_item_id' => $item->id, 'original_occurrence_date' => now()->addDay()->toDateString(),
                'action' => SchedRecurrenceException::ACTION_SKIPPED,
            ]);
            $workingHour = SchedWorkingHour::query()->create(['resource_id' => $resource->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);

            $this->assertSame($item->id, $booking->schedItem->id);
            $this->assertSame($item->id, $attendee->schedItem->id);
            $this->assertSame($item->id, $link->schedItem->id);
            $this->assertSame($item->id, $exception->schedItem->id);
            $this->assertSame($resource->id, $workingHour->resource->id);
            $this->assertTrue($resource->bookings->contains('id', $booking->id));
            $this->assertTrue($type->resources->contains('id', $resource->id));
        });
    }
}
