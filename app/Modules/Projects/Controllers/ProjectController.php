<?php

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Requests\StoreProjectRequest;
use App\Modules\Projects\Requests\UpdateProjectRequest;
use App\Modules\Projects\Services\ProjectService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['code', 'name', 'status', 'created_at'];

    public function __construct(
        protected ProjectService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'sort', 'direction', 'per_page');

        $projects = Project::query()
            ->with(['lead:id,name'])
            ->withCount('issues')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Project $p) => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'code' => $p->code,
                'name' => $p->name,
                'description' => $p->description,
                'status' => $p->status,
                'lead_id' => $p->lead_id,
                'lead_name' => $p->lead?->name,
                'issues_count' => $p->issues_count,
                'created_at_formatted' => $p->created_at?->format('d M Y'),
            ]);

        return Inertia::render('Projects/Projects/Index', [
            'projects' => $projects,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Projects/Projects/Create', [
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreProjectRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('projects.index')->with('success', 'Project created.');
    }

    public function edit(Project $project): Response
    {
        return Inertia::render('Projects/Projects/Edit', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'lead_id' => $project->lead_id,
                'start_date' => $project->start_date?->format('Y-m-d'),
                'end_date' => $project->end_date?->format('Y-m-d'),
            ],
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $this->service->update($project, $request->validated());

        return redirect()->route('projects.index')->with('success', 'Project updated.');
    }

    public function show(Project $project): Response
    {
        $issues = $project->issues()
            ->with('assignee:id,name')
            ->withCount('attachments')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Issue $issue) => [
                'id' => $issue->id,
                'code' => $issue->code,
                'title' => $issue->title,
                'type' => $issue->type,
                'status' => $issue->status,
                'priority' => $issue->priority,
                'assignee_id' => $issue->assignee_id,
                'assignee' => $issue->assignee?->name,
                'attachments_count' => $issue->attachments_count,
                'due_date' => $issue->due_date?->format('Y-m-d'),
                'due_date_formatted' => $issue->due_date?->format('d M Y'),
                'is_overdue' => $issue->due_date !== null
                    && $issue->due_date->isPast()
                    && $issue->status !== 'done',
            ]);

        return Inertia::render('Projects/Projects/Show', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'lead_name' => $project->lead?->name,
                'start_date' => $project->start_date?->format('Y-m-d'),
                'end_date' => $project->end_date?->format('Y-m-d'),
            ],
            'issues' => $issues,
            'stats' => [
                'total' => $issues->count(),
                'todo' => $issues->where('status', 'todo')->count(),
                'in_progress' => $issues->where('status', 'in_progress')->count(),
                'done' => $issues->where('status', 'done')->count(),
                'overdue' => $issues->where('is_overdue', true)->count(),
            ],
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function destroy(Project $project)
    {
        $this->service->delete($project);

        return redirect()->route('projects.index')->with('success', 'Project deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Project::class, fn (Project $project) => $this->service->delete($project));
    }
}
