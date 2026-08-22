<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Schedule\Data\CalendarItem;
use App\Modules\Schedule\Models\Resource;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Requests\StoreEventRequest;
use App\Modules\Schedule\Requests\StoreTaskRequest;
use App\Modules\Schedule\Services\CalendarService;
use App\Modules\Schedule\Services\EventService;
use App\Modules\Schedule\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** §3A — Main Calendar Dashboard: Tasks and Events together, Day/Week/Month/Agenda. */
class ScheduleDashboardController extends Controller
{
    private const VIEWS = ['day', 'week', 'month', 'agenda'];

    public function __construct(
        protected CalendarService $calendar,
        protected TaskService $tasks,
        protected EventService $events,
    ) {}

    public function index(Request $request): Response
    {
        $view = in_array($request->get('view'), self::VIEWS, true) ? $request->get('view') : 'month';
        $date = $request->get('date') ? Carbon::parse($request->get('date')) : Carbon::today();

        [$rangeStart, $rangeEnd] = $this->rangeFor($view, $date);

        $filters = array_filter([
            'owner_id' => $request->boolean('mine') ? $request->user()->id : ($request->get('owner_id') ?: null),
            'resource_id' => $request->get('resource_id') ?: null,
            'subject_type' => $request->get('subject_type') ?: null,
        ]);

        $items = $this->calendar->itemsForRange($rangeStart, $rangeEnd, $filters);

        return Inertia::render('Schedule/Dashboard/Index', [
            'view' => $view,
            'date' => $date->toDateString(),
            'rangeStart' => $rangeStart->toDateString(),
            'rangeEnd' => $rangeEnd->toDateString(),
            'items' => $items->map(fn (CalendarItem $i) => [
                'sched_item_id' => $i->schedItemId,
                'uuid' => $i->uuid,
                'type' => $i->type,
                'title' => $i->title,
                'start' => $i->start->format('Y-m-d\TH:i'),
                'end' => $i->end->format('Y-m-d\TH:i'),
                'date' => $i->start->toDateString(),
                'all_day' => $i->allDay,
                'status' => $i->status,
                'status_rail' => $i->statusRail,
                'owner_name' => $i->ownerName,
                'location' => $i->location,
                'is_recurring_instance' => $i->isRecurringInstance,
                'original_occurrence_date' => $i->originalOccurrenceDate,
            ])->values(),
            'filters' => [
                'mine' => $request->boolean('mine'),
                'owner_id' => $request->get('owner_id'),
                'resource_id' => $request->get('resource_id'),
                'subject_type' => $request->get('subject_type'),
            ],
            'owners' => User::query()->orderBy('name')->get(['id', 'name']),
            'resources' => Resource::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'subjectTypes' => SchedItem::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }

    /**
     * §3A side panel — full detail, attendees, resource(s) booked, conference link if
     * any, recurrence summary, and quick actions. Plain JSON (not Inertia), same
     * convention as CRM's/Central's dashboard drawer endpoints.
     */
    public function itemDrawer(SchedItem $schedItem)
    {
        $schedItem->load(['owner:id,name', 'attendees.user:id,name', 'bookings.resource:id,name', 'conferenceLink.conferenceProvider']);

        $isTask = $schedItem->type === SchedItem::TYPE_TASK;

        return response()->json([
            'id' => $schedItem->id,
            'type' => $schedItem->type,
            'title' => $schedItem->title,
            'description' => $schedItem->description,
            'status' => $schedItem->status,
            'priority' => $schedItem->priority,
            'due_at' => $schedItem->due_at?->format('d M Y H:i'),
            'start_at' => $schedItem->start_at?->format('d M Y H:i'),
            'end_at' => $schedItem->end_at?->format('d M Y H:i'),
            'owner_name' => $schedItem->owner?->name,
            'location' => $schedItem->location,
            'recurrence_rule' => $schedItem->recurrence_rule,
            'attendees' => $schedItem->attendees->map(fn ($a) => ['name' => $a->user?->name, 'role' => $a->role]),
            'resources' => $schedItem->bookings->map(fn ($b) => $b->resource?->name)->filter()->values(),
            'conference_link' => $schedItem->conferenceLink ? [
                'provider_name' => $schedItem->conferenceLink->conferenceProvider->name,
                'join_url' => $schedItem->conferenceLink->join_url,
            ] : null,
            'edit_url' => route($isTask ? 'schedule.tasks.edit' : 'schedule.events.edit', $schedItem->id),
            'mark_done_url' => $isTask ? route('schedule.tasks.markDone', $schedItem->id) : null,
            'cancel_url' => route($isTask ? 'schedule.tasks.cancel' : 'schedule.events.cancel', $schedItem->id),
        ]);
    }

    /** §3A quick-create: reuses TaskService — no duplicated logic — but returns to the dashboard (back()) instead of the Tasks index, since this is only ever posted from the dashboard's inline mini-form. */
    public function quickCreateTask(StoreTaskRequest $request)
    {
        $this->tasks->create($request->validated(), $request->user()->id);

        return back()->with('success', 'Task created.');
    }

    public function quickCreateEvent(StoreEventRequest $request)
    {
        $this->events->create($request->validated(), $request->user()->id);

        return back()->with('success', 'Event created.');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rangeFor(string $view, Carbon $date): array
    {
        return match ($view) {
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            'week' => [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()],
            'agenda' => [$date->copy()->startOfDay(), $date->copy()->addDays(30)->endOfDay()],
            default => [$date->copy()->startOfMonth()->startOfWeek(), $date->copy()->endOfMonth()->endOfWeek()],
        };
    }
}
