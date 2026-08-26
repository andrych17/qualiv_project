<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Data\Occurrence;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Models\SchedRecurrenceException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\Constraint\BetweenConstraint;

/**
 * §3F — expand a recurrence_rule into concrete occurrences for a date range
 * (never pre-materialized into rows), and manage per-occurrence exceptions
 * (skip / move / modify a single instance without breaking the series).
 * No constructor dependencies — AvailabilityService depends on this one
 * directly, so this must not depend back on it.
 */
class RecurrenceService
{
    /** Safety valve so a crafted COUNT/UNTIL can't force an unbounded expansion — §3F only needs "for a given date range." */
    private const MAX_OCCURRENCES = 366;

    /**
     * Expand a raw rule against a date range. Used both for a persisted item
     * (via expandItem()) and for a not-yet-saved one being validated at
     * save-time (EventService), which has no id/exceptions yet.
     *
     * @param  Collection<int, SchedRecurrenceException>|null  $exceptions
     * @return Collection<int, Occurrence>
     */
    public function expandRule(
        string $rrule,
        Carbon $anchorStart,
        Carbon $anchorEnd,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        ?Collection $exceptions = null,
    ): Collection {
        $exceptionsByDate = ($exceptions ?? collect())->keyBy(
            fn (SchedRecurrenceException $e) => $e->original_occurrence_date->format('Y-m-d')
        );

        $rule = new Rule($rrule, $anchorStart, $anchorEnd);
        $transformer = new ArrayTransformer;
        $constraint = new BetweenConstraint($rangeStart, $rangeEnd, true);
        $recurrences = $transformer->transform($rule, $constraint);

        $occurrences = collect();

        foreach ($recurrences as $recurrence) {
            if ($occurrences->count() >= self::MAX_OCCURRENCES) {
                break;
            }

            $originalDate = Carbon::instance($recurrence->getStart())->startOfDay();
            $exception = $exceptionsByDate->get($originalDate->format('Y-m-d'));

            $start = Carbon::instance($recurrence->getStart());
            $end = Carbon::instance($recurrence->getEnd());
            $status = 'scheduled';

            // Skipped occurrences are still returned (status carried through, base time
            // untouched) rather than dropped — the occurrences panel needs them to offer
            // "Restore"; callers that book/check availability reject status === 'skipped'
            // themselves (see AvailabilityService::findConflicts, EventService).
            if ($exception) {
                $status = $exception->action;
                if ($exception->override_start_at) {
                    $start = $exception->override_start_at->clone();
                    $end = $exception->override_end_at?->clone() ?? $start->clone();
                }
            }

            $occurrences->push(new Occurrence($originalDate, $start, $end, $status));
        }

        return $occurrences;
    }

    /** @return Collection<int, Occurrence> */
    public function expandItem(SchedItem $item, Carbon $rangeStart, Carbon $rangeEnd): Collection
    {
        if (! $item->recurrence_rule) {
            return collect();
        }

        $isTask = $item->type === SchedItem::TYPE_TASK;
        $anchorStart = $isTask ? $item->due_at : $item->start_at;
        $anchorEnd = $isTask ? $item->due_at : $item->end_at;

        return $this->expandRule(
            $item->recurrence_rule,
            $anchorStart,
            $anchorEnd,
            $rangeStart,
            $rangeEnd,
            $item->recurrenceExceptions()->get(),
        );
    }

    /** §3F: delete or reschedule a single instance without breaking the series. */
    public function skipOccurrence(SchedItem $item, Carbon $originalDate): void
    {
        SchedRecurrenceException::query()->updateOrCreate(
            ['sched_item_id' => $item->id, 'original_occurrence_date' => $originalDate->toDateString()],
            ['action' => SchedRecurrenceException::ACTION_SKIPPED, 'override_start_at' => null, 'override_end_at' => null],
        );
    }

    public function rescheduleOccurrence(SchedItem $item, Carbon $originalDate, Carbon $newStart, Carbon $newEnd): void
    {
        $action = $newStart->isSameDay($originalDate)
            ? SchedRecurrenceException::ACTION_MODIFIED
            : SchedRecurrenceException::ACTION_MOVED;

        SchedRecurrenceException::query()->updateOrCreate(
            ['sched_item_id' => $item->id, 'original_occurrence_date' => $originalDate->toDateString()],
            ['action' => $action, 'override_start_at' => $newStart, 'override_end_at' => $newEnd],
        );
    }

    /** Undo a skip/move/modify — the occurrence reverts to its plain expanded time. */
    public function restoreOccurrence(SchedItem $item, Carbon $originalDate): void
    {
        SchedRecurrenceException::query()
            ->where('sched_item_id', $item->id)
            ->where('original_occurrence_date', $originalDate->toDateString())
            ->delete();
    }

    /**
     * Ready-to-serialize occurrences for the Edit page's "Upcoming occurrences"
     * panel — the concrete surface for §3F's "handle this-occurrence-only
     * edits/cancellations" purpose, ahead of §3A's full calendar view.
     *
     * @return list<array{original_date: string, start: string, end: string, status: string}>
     */
    public function upcomingOccurrencesFor(SchedItem $item, int $withinDays = 90, int $limit = 20): array
    {
        if (! $item->recurrence_rule) {
            return [];
        }

        $rangeStart = Carbon::now()->startOfDay();
        $rangeEnd = $rangeStart->copy()->addDays($withinDays);

        return $this->expandItem($item, $rangeStart, $rangeEnd)
            ->take($limit)
            ->map(fn (Occurrence $occ) => [
                'original_date' => $occ->originalDate->format('Y-m-d'),
                'start' => $occ->start->format('Y-m-d\TH:i'),
                'end' => $occ->end->format('Y-m-d\TH:i'),
                'status' => $occ->status,
            ])
            ->values()
            ->all();
    }
}
