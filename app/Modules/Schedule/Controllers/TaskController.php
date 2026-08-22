<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Requests\RescheduleOccurrenceRequest;
use App\Modules\Schedule\Requests\SkipOccurrenceRequest;
use App\Modules\Schedule\Requests\StoreTaskRequest;
use App\Modules\Schedule\Requests\UpdateTaskRequest;
use App\Modules\Schedule\Services\RecurrenceService;
use App\Modules\Schedule\Services\TaskService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** §3B — Task Management: single-owner to-dos, optional watchers. */
class TaskController extends Controller
{
    private const SORTABLE = ['title', 'due_at', 'created_at'];

    public function __construct(
        protected TaskService $service,
        protected RecurrenceService $recurrence,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'priority', 'owner_id', 'sort', 'direction', 'per_page');

        $tasks = SchedItem::query()
            ->tasks()
            ->with('owner:id,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'due_at', 'asc'),
                fn ($query) => $query->orderBy('due_at'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (SchedItem $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'owner_name' => $t->owner?->name,
                'priority' => $t->priority,
                'status' => $t->status,
                'due_at_formatted' => $t->due_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Schedule/Tasks/Index', [
            'tasks' => $tasks,
            'filters' => $filters,
            'owners' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Schedule/Tasks/Create', $this->formProps());
    }

    public function store(StoreTaskRequest $request)
    {
        $this->service->create($request->validated(), $request->user()->id);

        return redirect()->route('schedule.tasks.index')->with('success', 'Task created.');
    }

    public function edit(SchedItem $task): Response
    {
        return Inertia::render('Schedule/Tasks/Edit', [
            ...$this->formProps(),
            'task' => $this->toFormData($task),
            'occurrences' => $this->recurrence->upcomingOccurrencesFor($task),
        ]);
    }

    public function update(UpdateTaskRequest $request, SchedItem $task)
    {
        $this->service->update($task, $request->validated());

        return redirect()->route('schedule.tasks.index')->with('success', 'Task updated.');
    }

    public function destroy(SchedItem $task)
    {
        $this->service->delete($task);

        return redirect()->route('schedule.tasks.index')->with('success', 'Task deleted.');
    }

    /** §3B: "Mark Done" — one-click from the list, no need to open the item. */
    public function markDone(SchedItem $task)
    {
        $this->service->markDone($task);

        return back()->with('success', 'Task marked done.');
    }

    /** §3A: drawer quick action. */
    public function cancel(SchedItem $task)
    {
        $this->service->cancel($task);

        return back()->with('success', 'Task cancelled.');
    }

    /** §3F: skip a single occurrence without breaking the series. */
    public function skipOccurrence(SkipOccurrenceRequest $request, SchedItem $task)
    {
        $this->service->skipOccurrence($task, Carbon::parse($request->validated('original_occurrence_date')));

        return back()->with('success', 'Occurrence skipped.');
    }

    /** §3F: reschedule ("moved") or retime ("modified") a single occurrence. */
    public function rescheduleOccurrence(RescheduleOccurrenceRequest $request, SchedItem $task)
    {
        $data = $request->validated();

        $this->service->rescheduleOccurrence(
            $task,
            Carbon::parse($data['original_occurrence_date']),
            Carbon::parse($data['start_at']),
        );

        return back()->with('success', 'Occurrence rescheduled.');
    }

    public function restoreOccurrence(SkipOccurrenceRequest $request, SchedItem $task)
    {
        $this->service->restoreOccurrence($task, Carbon::parse($request->validated('original_occurrence_date')));

        return back()->with('success', 'Occurrence restored.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'owners' => User::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(SchedItem $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'due_at' => $task->due_at?->format('Y-m-d\TH:i'),
            'priority' => $task->priority,
            'status' => $task->status,
            'owner_id' => $task->owner_id,
            'subject_type' => $task->subject_type,
            'subject_id' => $task->subject_id,
            'recurrence_rule' => $task->recurrence_rule,
            'watcher_ids' => $task->attendees()->pluck('user_id'),
        ];
    }
}
