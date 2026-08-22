<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Models\Resource;
use App\Modules\Schedule\Models\SchedAttendee;
use App\Modules\Schedule\Models\SchedBooking;
use App\Modules\Schedule\Models\SchedItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** §3C — Event Management: time-blocked, usually multi-attendee, with resource booking (§3D/§3E) and an optional conference link (§3G). */
class EventService
{
    public function __construct(
        protected AvailabilityService $availability,
        protected RecurrenceService $recurrence,
        protected ConferenceService $conference,
    ) {}

    public function create(array $data, int $actorId): SchedItem
    {
        $startAt = Carbon::parse($data['start_at']);
        $endAt = Carbon::parse($data['end_at']);
        $resourceIds = array_unique($data['resource_ids'] ?? []);
        $recurrenceRule = $data['recurrence_rule'] ?? null;

        $this->assertResourcesFree($resourceIds, $startAt, $endAt, $recurrenceRule);

        return DB::transaction(function () use ($data, $actorId, $startAt, $endAt, $resourceIds, $recurrenceRule) {
            $event = SchedItem::query()->create([
                'type' => SchedItem::TYPE_EVENT,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'owner_id' => $data['owner_id'] ?? $actorId,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'recurrence_rule' => $recurrenceRule,
                'status' => 'scheduled',
                'start_at' => $startAt,
                'end_at' => $endAt,
                'all_day' => $data['all_day'] ?? false,
                'location' => $data['location'] ?? null,
            ]);

            $this->syncAttendees($event, $data['attendee_ids'] ?? []);
            $this->syncBookings($event, $resourceIds);
            $this->syncConference($event, $data);

            return $event;
        });
    }

    public function update(SchedItem $event, array $data): SchedItem
    {
        $startAt = Carbon::parse($data['start_at']);
        $endAt = Carbon::parse($data['end_at']);
        $resourceIds = array_unique($data['resource_ids'] ?? []);
        $recurrenceRule = $data['recurrence_rule'] ?? null;

        if ($data['status'] !== 'cancelled') {
            $this->assertResourcesFree($resourceIds, $startAt, $endAt, $recurrenceRule, excludeSchedItemId: $event->id);
        }

        return DB::transaction(function () use ($event, $data, $startAt, $endAt, $resourceIds, $recurrenceRule) {
            $event->update([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'owner_id' => $data['owner_id'] ?? $event->owner_id,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'recurrence_rule' => $recurrenceRule,
                'status' => $data['status'],
                'start_at' => $startAt,
                'end_at' => $endAt,
                'all_day' => $data['all_day'] ?? false,
                'location' => $data['location'] ?? null,
            ]);

            $this->syncAttendees($event, $data['attendee_ids'] ?? []);
            $this->syncBookings($event, $resourceIds);
            $this->syncConference($event, $data);

            return $event->refresh();
        });
    }

    public function delete(SchedItem $event): void
    {
        DB::transaction(function () use ($event) {
            $this->conference->detach($event);
            $event->attendees()->delete();
            $event->bookings()->delete();
            $event->recurrenceExceptions()->delete();
            $event->delete();
        });
    }

    /** §3A: drawer quick action — one-click cancel, alternative to opening the full edit form. Frees the resource: AvailabilityService already excludes cancelled items from conflict checks. */
    public function cancel(SchedItem $event): SchedItem
    {
        $event->update(['status' => 'cancelled']);

        return $event;
    }

    /** §3F: no-op for a non-recurring event. */
    public function skipOccurrence(SchedItem $event, Carbon $originalDate): void
    {
        $this->recurrence->skipOccurrence($event, $originalDate);
    }

    /** §3F/§3E: re-validates resource availability for the new window before committing the override. */
    public function rescheduleOccurrence(SchedItem $event, Carbon $originalDate, Carbon $newStart, Carbon $newEnd): void
    {
        $resourceIds = $event->bookings()->pluck('resource_id')->all();

        foreach ($resourceIds as $resourceId) {
            $this->assertResourceFreeForOccurrence($resourceId, $newStart, $newEnd, $event->id);
        }

        $this->recurrence->rescheduleOccurrence($event, $originalDate, $newStart, $newEnd);
    }

    public function restoreOccurrence(SchedItem $event, Carbon $originalDate): void
    {
        $this->recurrence->restoreOccurrence($event, $originalDate);
    }

    /** @param  list<int>  $userIds */
    private function syncAttendees(SchedItem $event, array $userIds): void
    {
        SchedAttendee::query()->where('sched_item_id', $event->id)->delete();

        foreach (array_unique($userIds) as $userId) {
            SchedAttendee::query()->create([
                'sched_item_id' => $event->id,
                'user_id' => $userId,
                'role' => SchedAttendee::ROLE_ATTENDEE,
            ]);
        }
    }

    /** @param  list<int>  $resourceIds */
    private function syncBookings(SchedItem $event, array $resourceIds): void
    {
        SchedBooking::query()->where('sched_item_id', $event->id)->delete();

        foreach ($resourceIds as $resourceId) {
            SchedBooking::query()->create([
                'sched_item_id' => $event->id,
                'resource_id' => $resourceId,
            ]);
        }
    }

    /** §3G: resolve to the desired end-state — attach/replace if a provider was picked, detach if not (or the event's own resources changed and now book none, which never removes a conference link on its own). */
    private function syncConference(SchedItem $event, array $data): void
    {
        $providerCode = $data['conference_provider_code'] ?? null;

        if ($providerCode) {
            $this->conference->attach($event, $providerCode, $data['conference_manual_url'] ?? null);

            return;
        }

        if ($data['conference_remove'] ?? false) {
            $this->conference->detach($event);
        }
    }

    /**
     * §3E/§3F: called synchronously on save — blocks a conflicting save with a
     * clear error (DESIGN.md voice guidance). For a recurring event, every
     * occurrence (up to the same safety cap RecurrenceService applies) is
     * checked, not just the first — a weekly recurring booking must not
     * silently conflict on week 3.
     *
     * @param  list<int>  $resourceIds
     */
    private function assertResourcesFree(array $resourceIds, Carbon $startAt, Carbon $endAt, ?string $recurrenceRule, ?int $excludeSchedItemId = null): void
    {
        if (! $resourceIds) {
            return;
        }

        $windows = $recurrenceRule
            ? $this->recurrence->expandRule($recurrenceRule, $startAt, $endAt, $startAt, $startAt->copy()->addYears(5))
                ->reject(fn ($occ) => $occ->status === 'skipped')
                ->map(fn ($occ) => [$occ->start, $occ->end])
            : collect([[$startAt, $endAt]]);

        foreach ($resourceIds as $resourceId) {
            foreach ($windows as [$occStart, $occEnd]) {
                $this->assertResourceFreeForOccurrence($resourceId, $occStart, $occEnd, $excludeSchedItemId);
            }
        }
    }

    private function assertResourceFreeForOccurrence(int $resourceId, Carbon $startAt, Carbon $endAt, ?int $excludeSchedItemId): void
    {
        $conflicts = $this->availability->findConflicts($resourceId, $startAt, $endAt, $excludeSchedItemId);

        if ($conflicts->isNotEmpty()) {
            $resourceName = Resource::query()->whereKey($resourceId)->value('name') ?? 'That resource';
            $conflict = $conflicts->first();
            $window = $conflict->start->format('d M Y, g:i A').'–'.$conflict->end->format('g:i A');

            throw ValidationException::withMessages([
                'resource_ids' => "{$resourceName} is already booked {$window}. Choose another time or resource.",
            ]);
        }

        if (! $this->availability->fitsWorkingHours($resourceId, $startAt, $endAt)) {
            $resourceName = Resource::query()->whereKey($resourceId)->value('name') ?? 'That resource';

            throw ValidationException::withMessages([
                'resource_ids' => "{$resourceName} is outside its working hours on ".$startAt->format('d M Y').'. Choose another time or resource.',
            ]);
        }
    }
}
