<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Schedule\Models\ConferenceProvider;
use App\Modules\Schedule\Models\Resource;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Requests\RescheduleOccurrenceRequest;
use App\Modules\Schedule\Requests\SkipOccurrenceRequest;
use App\Modules\Schedule\Requests\StoreEventRequest;
use App\Modules\Schedule\Requests\UpdateEventRequest;
use App\Modules\Schedule\Services\EventService;
use App\Modules\Schedule\Services\RecurrenceService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3C — Event Management: time-blocked, usually multi-attendee, with
 * resource booking (§3D/§3E) and an optional conference link (§3G).
 */
class EventController extends Controller
{
    private const SORTABLE = ['title', 'start_at', 'created_at'];

    public function __construct(
        protected EventService $service,
        protected RecurrenceService $recurrence,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'owner_id', 'sort', 'direction', 'per_page');

        $events = SchedItem::query()
            ->events()
            ->with('owner:id,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'start_at', 'asc'),
                fn ($query) => $query->orderBy('start_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (SchedItem $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'owner_name' => $e->owner?->name,
                'location' => $e->location,
                'status' => $e->status,
                'start_at_formatted' => $e->start_at?->format('d M Y H:i'),
                'end_at_formatted' => $e->end_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Schedule/Events/Index', [
            'events' => $events,
            'filters' => $filters,
            'owners' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Schedule/Events/Create', $this->formProps());
    }

    public function store(StoreEventRequest $request)
    {
        $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('schedule.events.index')->with('success', 'Event created.');
    }

    public function edit(SchedItem $event): Response
    {
        return Inertia::render('Schedule/Events/Edit', [
            ...$this->formProps(),
            'event' => $this->toFormData($event),
            'occurrences' => $this->recurrence->upcomingOccurrencesFor($event),
        ]);
    }

    public function update(UpdateEventRequest $request, SchedItem $event)
    {
        $this->service->update($event, $request->validated());

        return redirect()->route('schedule.events.index')->with('success', 'Event updated.');
    }

    public function destroy(SchedItem $event)
    {
        $this->service->delete($event);

        return redirect()->route('schedule.events.index')->with('success', 'Event deleted.');
    }

    /** §3A: drawer quick action. */
    public function cancel(SchedItem $event)
    {
        $this->service->cancel($event);

        return back()->with('success', 'Event cancelled.');
    }

    /** §3F: skip a single occurrence without breaking the series. */
    public function skipOccurrence(SkipOccurrenceRequest $request, SchedItem $event)
    {
        $this->service->skipOccurrence($event, Carbon::parse($request->validated('original_occurrence_date')));

        return back()->with('success', 'Occurrence skipped.');
    }

    /** §3F/§3E: reschedule ("moved") or retime ("modified") a single occurrence — re-validates resource availability for the new window. */
    public function rescheduleOccurrence(RescheduleOccurrenceRequest $request, SchedItem $event)
    {
        $data = $request->validated();
        $newStart = Carbon::parse($data['start_at']);
        $newEnd = ! empty($data['end_at']) ? Carbon::parse($data['end_at']) : $newStart->clone();

        $this->service->rescheduleOccurrence($event, Carbon::parse($data['original_occurrence_date']), $newStart, $newEnd);

        return back()->with('success', 'Occurrence rescheduled.');
    }

    public function restoreOccurrence(SkipOccurrenceRequest $request, SchedItem $event)
    {
        $this->service->restoreOccurrence($event, Carbon::parse($request->validated('original_occurrence_date')));

        return back()->with('success', 'Occurrence restored.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'owners' => User::query()->orderBy('name')->get(['id', 'name']),
            'resources' => Resource::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'conferenceProviders' => ConferenceProvider::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(SchedItem $event): array
    {
        $link = $event->conferenceLink;

        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'start_at' => $event->start_at?->format('Y-m-d\TH:i'),
            'end_at' => $event->end_at?->format('Y-m-d\TH:i'),
            'all_day' => $event->all_day,
            'location' => $event->location,
            'status' => $event->status,
            'owner_id' => $event->owner_id,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'recurrence_rule' => $event->recurrence_rule,
            'attendee_ids' => $event->attendees()->pluck('user_id'),
            'resource_ids' => $event->bookings()->pluck('resource_id'),
            'conference_link' => $link ? [
                'provider_code' => $link->conferenceProvider->code,
                'provider_name' => $link->conferenceProvider->name,
                'join_url' => $link->join_url,
            ] : null,
        ];
    }
}
