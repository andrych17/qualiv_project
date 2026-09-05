<?php

namespace Tests\Feature\Schedule;

use App\Models\User;
use App\Modules\Schedule\Models\SchedBooking;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Models\SchedRecurrenceException;
use App\Modules\Schedule\Models\SchedWorkingHour;
use App\Modules\Schedule\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpSchedule;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3E — AvailabilityService, exercised directly against the tenant DB (below the HTTP/validation layer, which already rejects most of these before the service is reached). */
class ScheduleAvailabilityTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpSchedule;
    use SetsUpTenant;

    private function book(int $resourceId, Carbon $start, Carbon $end, string $status = 'scheduled', ?string $recurrenceRule = null): SchedItem
    {
        $item = SchedItem::query()->create([
            'type' => SchedItem::TYPE_EVENT, 'title' => 'Booking', 'owner_id' => User::query()->first()->id,
            'status' => $status, 'start_at' => $start, 'end_at' => $end, 'recurrence_rule' => $recurrenceRule,
        ]);
        SchedBooking::query()->create(['sched_item_id' => $item->id, 'resource_id' => $resourceId]);

        return $item;
    }

    public function test_is_free_true_with_no_bookings_and_no_working_hours(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $resourceId = $this->makeResource()->id;
            $service = app(AvailabilityService::class);

            $this->assertTrue($service->isFree($resourceId, now()->addDay(), now()->addDay()->addHour()));
        });
    }

    public function test_find_conflicts_detects_overlap_and_excludes_cancelled_and_excluded_id(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $resourceId = $this->makeResource()->id;
            $start = Carbon::parse('2026-11-16 10:00:00');
            $end = $start->copy()->addHour();

            $item = $this->book($resourceId, $start, $end);
            $service = app(AvailabilityService::class);

            $conflicts = $service->findConflicts($resourceId, $start->copy()->addMinutes(30), $end->copy()->addMinutes(30));
            $this->assertCount(1, $conflicts);
            $this->assertSame($item->id, $conflicts->first()->item->id);

            // Excluding the conflicting item itself (e.g. editing it) clears the conflict.
            $this->assertCount(0, $service->findConflicts($resourceId, $start, $end, $item->id));

            // A non-overlapping window is free.
            $this->assertCount(0, $service->findConflicts($resourceId, $end->copy()->addHour(), $end->copy()->addHours(2)));

            // Cancelling the booking frees the resource.
            $item->update(['status' => 'cancelled']);
            $this->assertCount(0, $service->findConflicts($resourceId, $start, $end));
        });
    }

    public function test_find_conflicts_handles_recurring_candidates_and_skipped_occurrences(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $resourceId = $this->makeResource()->id;
            $anchor = Carbon::parse('next monday')->setTime(9, 0);
            $item = $this->book($resourceId, $anchor, $anchor->copy()->addHour(), recurrenceRule: 'FREQ=WEEKLY;COUNT=4');

            $service = app(AvailabilityService::class);

            $secondOccurrence = $anchor->copy()->addWeek();
            $this->assertCount(1, $service->findConflicts($resourceId, $secondOccurrence, $secondOccurrence->copy()->addHour()));

            SchedRecurrenceException::query()->create([
                'sched_item_id' => $item->id,
                'original_occurrence_date' => $secondOccurrence->toDateString(),
                'action' => SchedRecurrenceException::ACTION_SKIPPED,
            ]);

            $this->assertCount(0, $service->findConflicts($resourceId, $secondOccurrence, $secondOccurrence->copy()->addHour()));

            $thirdOccurrence = $anchor->copy()->addWeeks(2);
            $this->assertCount(1, $service->findConflicts($resourceId, $thirdOccurrence, $thirdOccurrence->copy()->addHour()));
        });
    }

    public function test_fits_working_hours_true_when_no_rows_are_defined(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $resourceId = $this->makeResource()->id;
            $service = app(AvailabilityService::class);

            $this->assertTrue($service->fitsWorkingHours($resourceId, now(), now()->addHour()));
        });
    }

    public function test_fits_working_hours_false_when_the_day_has_no_row(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $resource = $this->makeResource();
            SchedWorkingHour::query()->create(['resource_id' => $resource->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);

            $sunday = Carbon::parse('next sunday')->setTime(10, 0);
            $service = app(AvailabilityService::class);

            $this->assertFalse($service->fitsWorkingHours($resource->id, $sunday, $sunday->copy()->addHour()));
        });
    }

    public function test_fits_working_hours_false_when_the_booking_spills_outside_the_window(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $resource = $this->makeResource();
            SchedWorkingHour::query()->create(['resource_id' => $resource->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);

            $monday = Carbon::parse('next monday');
            $service = app(AvailabilityService::class);

            $this->assertFalse($service->fitsWorkingHours($resource->id, $monday->copy()->setTime(8, 0), $monday->copy()->setTime(9, 30)));
            $this->assertFalse($service->fitsWorkingHours($resource->id, $monday->copy()->setTime(16, 30), $monday->copy()->setTime(18, 0)));
            $this->assertTrue($service->fitsWorkingHours($resource->id, $monday->copy()->setTime(9, 30), $monday->copy()->setTime(16, 30)));
        });
    }

    public function test_is_free_is_false_when_outside_working_hours_even_without_a_conflict(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $resource = $this->makeResource();
            SchedWorkingHour::query()->create(['resource_id' => $resource->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']);

            $sunday = Carbon::parse('next sunday')->setTime(10, 0);
            $service = app(AvailabilityService::class);

            $this->assertFalse($service->isFree($resource->id, $sunday, $sunday->copy()->addHour()));
        });
    }
}
