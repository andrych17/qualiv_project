<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Data\CalendarItem;
use App\Modules\Schedule\Models\SchedItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * §3A — Main Calendar Dashboard's data source. Tasks and Events together,
 * recurring items expanded server-side for the visible range only (never
 * materialized into rows), each tagged with a Status Rail per DESIGN.md.
 */
class CalendarService
{
    /** Task "due soon" window — same threshold as CRM's HasSlaState::DUE_SOON_HOURS, for a consistent vocabulary across modules. */
    private const DUE_SOON_HOURS = 24;

    public function __construct(
        protected RecurrenceService $recurrence,
        protected AvailabilityService $availability,
    ) {}

    /**
     * @param  array{owner_id?: int, resource_id?: int, subject_type?: string}  $filters
     * @return Collection<int, CalendarItem>
     */
    public function itemsForRange(Carbon $rangeStart, Carbon $rangeEnd, array $filters = []): Collection
    {
        $items = SchedItem::query()
            ->with(['owner:id,name', 'bookings.resource'])
            ->when($filters['owner_id'] ?? null, fn ($q, $id) => $q->where('owner_id', $id))
            ->when($filters['subject_type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type))
            ->when(
                $filters['resource_id'] ?? null,
                fn ($q, $resourceId) => $q->whereHas('bookings', fn ($q2) => $q2->where('resource_id', $resourceId))
            )
            ->where(function ($query) use ($rangeStart, $rangeEnd) {
                // Recurring items are fetched regardless of their own anchor date — it may
                // sit far outside this range while an occurrence still falls inside it.
                $query->whereNotNull('recurrence_rule')
                    ->orWhere(function ($query) use ($rangeStart, $rangeEnd) {
                        $query->where(function ($q) use ($rangeStart, $rangeEnd) {
                            $q->where('type', SchedItem::TYPE_TASK)->whereBetween('due_at', [$rangeStart, $rangeEnd]);
                        })->orWhere(function ($q) use ($rangeStart, $rangeEnd) {
                            $q->where('type', SchedItem::TYPE_EVENT)->where('start_at', '<', $rangeEnd)->where('end_at', '>', $rangeStart);
                        });
                    });
            })
            ->get();

        $result = collect();

        foreach ($items as $item) {
            if ($item->recurrence_rule) {
                foreach ($this->recurrence->expandItem($item, $rangeStart, $rangeEnd) as $occurrence) {
                    if ($occurrence->status === 'skipped') {
                        continue;
                    }

                    $result->push($this->toCalendarItem($item, $occurrence->start, $occurrence->end, $occurrence->originalDate->toDateString(), true));
                }

                continue;
            }

            $start = $item->type === SchedItem::TYPE_TASK ? $item->due_at : $item->start_at;
            $end = $item->type === SchedItem::TYPE_TASK ? $item->due_at : $item->end_at;

            $result->push($this->toCalendarItem($item, $start, $end, null, false));
        }

        return $result->sortBy(fn (CalendarItem $ci) => $ci->start->timestamp)->values();
    }

    private function toCalendarItem(SchedItem $item, Carbon $start, Carbon $end, ?string $originalOccurrenceDate, bool $isRecurringInstance): CalendarItem
    {
        return new CalendarItem(
            schedItemId: $item->id,
            uuid: $item->uuid,
            type: $item->type,
            title: $item->title,
            start: $start,
            end: $end,
            allDay: $item->all_day,
            status: $item->status,
            statusRail: $this->statusRail($item, $start, $end, $isRecurringInstance),
            ownerName: $item->owner?->name,
            location: $item->location,
            isRecurringInstance: $isRecurringInstance,
            originalOccurrenceDate: $originalOccurrenceDate,
        );
    }

    /** danger (overdue/conflict) > warning (due soon) > success (done) > info (recurring instance) > neutral. */
    private function statusRail(SchedItem $item, Carbon $start, Carbon $end, bool $isRecurringInstance): string
    {
        if ($item->type === SchedItem::TYPE_TASK) {
            if ($item->status === 'done') {
                return 'success';
            }

            if ($item->status !== 'cancelled') {
                if ($start->isPast()) {
                    return 'danger';
                }

                if ($start->lessThanOrEqualTo(now()->addHours(self::DUE_SOON_HOURS))) {
                    return 'warning';
                }
            }
        } elseif ($item->status !== 'cancelled' && $this->hasConflict($item, $start, $end)) {
            return 'danger';
        }

        return $isRecurringInstance ? 'info' : 'neutral';
    }

    private function hasConflict(SchedItem $item, Carbon $start, Carbon $end): bool
    {
        foreach ($item->bookings as $booking) {
            if ($this->availability->findConflicts($booking->resource_id, $start, $end, $item->id)->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }
}
