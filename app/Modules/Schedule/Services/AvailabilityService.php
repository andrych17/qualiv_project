<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Data\ConflictingBooking;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Models\SchedWorkingHour;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * §3E — the one reusable service every other form calls before confirming a
 * booking. v1 only books Resources (sched_bookings has no user_id column), so
 * this checks resource availability only — not attendee/owner calendars.
 */
class AvailabilityService
{
    public function __construct(
        protected RecurrenceService $recurrence,
    ) {}

    public function isFree(int $resourceId, CarbonInterface $startAt, CarbonInterface $endAt, ?int $excludeSchedItemId = null): bool
    {
        return $this->findConflicts($resourceId, $startAt, $endAt, $excludeSchedItemId)->isEmpty()
            && $this->fitsWorkingHours($resourceId, $startAt, $endAt);
    }

    /**
     * §3F: a recurring candidate's base start_at/end_at is only its first
     * occurrence — checked here per expanded occurrence within a narrow
     * window around the tested slot, so a weekly booking doesn't silently
     * conflict on week 3.
     *
     * @return Collection<int, ConflictingBooking>
     */
    public function findConflicts(int $resourceId, CarbonInterface $startAt, CarbonInterface $endAt, ?int $excludeSchedItemId = null): Collection
    {
        $candidates = SchedItem::query()
            ->whereHas('bookings', fn ($query) => $query->where('resource_id', $resourceId))
            ->where('status', '!=', 'cancelled')
            ->when($excludeSchedItemId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->where(function ($query) use ($startAt, $endAt) {
                $query->whereNotNull('recurrence_rule')
                    ->orWhere(function ($query) use ($startAt, $endAt) {
                        $query->where('start_at', '<', $endAt)->where('end_at', '>', $startAt);
                    });
            })
            ->get();

        $conflicts = collect();

        foreach ($candidates as $candidate) {
            if (! $candidate->recurrence_rule) {
                $conflicts->push(new ConflictingBooking($candidate, $candidate->start_at, $candidate->end_at));

                continue;
            }

            $windowStart = $startAt->copy()->subDay();
            $windowEnd = $endAt->copy()->addDay();

            $occurrence = $this->recurrence->expandItem($candidate, $windowStart, $windowEnd)
                ->first(fn ($occ) => $occ->status !== 'skipped' && $occ->start->lt($endAt) && $occ->end->gt($startAt));

            if ($occurrence) {
                $conflicts->push(new ConflictingBooking($candidate, $occurrence->start, $occurrence->end));
            }
        }

        return $conflicts;
    }

    /**
     * §3D/§3E: if the resource has any working-hours rows at all, every day the
     * booking spans must have a row for that weekday, and the booking's portion
     * of that day must fall inside it. No rows at all = available 24/7.
     */
    public function fitsWorkingHours(int $resourceId, CarbonInterface $startAt, CarbonInterface $endAt): bool
    {
        $hoursByDay = SchedWorkingHour::query()->where('resource_id', $resourceId)->get()->keyBy('day_of_week');

        if ($hoursByDay->isEmpty()) {
            return true;
        }

        $day = $startAt->copy()->startOfDay();
        while ($day->lte($endAt)) {
            $hours = $hoursByDay->get($day->dayOfWeek);
            if (! $hours) {
                return false;
            }

            $windowStart = $day->copy()->setTimeFromTimeString($hours->start_time);
            $windowEnd = $day->copy()->setTimeFromTimeString($hours->end_time);
            $segmentStart = $startAt->greaterThan($day) ? $startAt : $day;
            $segmentEnd = $endAt->lessThan($day->copy()->endOfDay()) ? $endAt : $day->copy()->endOfDay();

            if ($segmentStart->lessThan($windowStart) || $segmentEnd->greaterThan($windowEnd)) {
                return false;
            }

            $day->addDay();
        }

        return true;
    }
}
