<?php

namespace App\Modules\WNE\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WNE\Exceptions\WorkflowEngineException;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use App\Modules\WNE\Requests\CompleteWorkflowTaskRequest;
use App\Modules\WNE\Services\TaskInboxService;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3H — My Approvals / Task Inbox: the shared "what's waiting on me" queue. */
class TaskInboxController extends Controller
{
    public function __construct(
        protected TaskInboxService $inbox,
        protected WorkflowService $workflow,
    ) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $search = trim((string) $request->query('search', ''));
        $subjectType = $request->query('subject_type');
        $slaState = $request->query('sla_state');
        $sortKey = in_array($request->query('sort'), ['due_at', 'started_at'], true) ? $request->query('sort') : 'due_at';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $perPage = (int) ($request->query('per_page') ?: 20);

        $tasks = $this->inbox->myTasksQuery($userId)
            ->with(['step.version.definition', 'instance'])
            ->when($subjectType, fn ($q) => $q->whereHas('instance', fn ($q2) => $q2->where('subject_type', $subjectType)))
            ->filterSlaState($slaState)
            ->when($search !== '', fn ($q) => $q->where(
                fn ($q2) => $q2->whereHas('step', fn ($q3) => $q3->where('step_code', 'ilike', "%{$search}%"))
                    ->orWhereHas('step.version.definition', fn ($q3) => $q3->where('name', 'ilike', "%{$search}%"))
            ))
            ->urgencyFirst()
            ->orderBy($sortKey, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (WrkflowInstanceStep $task) => [
                'id' => $task->id,
                'step_code' => $task->step->step_code,
                'prompt' => $task->step->config['prompt'] ?? "Complete step '{$task->step->step_code}'",
                'decisions' => $task->step->config['decisions'] ?? ['approve', 'reject'],
                'workflow_name' => $task->step->version->definition->name,
                'subject_type' => $task->instance->subject_type,
                'subject_id' => $task->instance->subject_id,
                'status' => $task->status,
                'sla_state' => $task->sla_state,
                'due_at_formatted' => $task->due_at?->format('Y-m-d H:i'),
                'started_at_formatted' => $task->started_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('WNE/MyTasks/Index', [
            'tasks' => $tasks,
            'filters' => [
                'search' => $search,
                'subject_type' => $subjectType,
                'sla_state' => $slaState,
                'sort' => $sortKey,
                'direction' => $direction,
                'per_page' => (string) $perPage,
            ],
            'subjectTypes' => WrkflowInstance::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }

    public function complete(CompleteWorkflowTaskRequest $request, WrkflowInstanceStep $instanceStep): RedirectResponse
    {
        $allowedDecisions = $instanceStep->step->config['decisions'] ?? ['approve', 'reject'];

        if (! in_array($request->validated('decision'), $allowedDecisions, true)) {
            return back()->withErrors(['decision' => 'That decision is not offered for this step.']);
        }

        try {
            $this->workflow->completeTask($instanceStep, $request->validated('decision'), $request->validated('comment'), $request->user()->id);
        } catch (WorkflowEngineException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('success', 'Decision recorded.');
    }
}
