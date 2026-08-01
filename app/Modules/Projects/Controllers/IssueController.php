<?php

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\IssueComment;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Requests\StoreIssueRequest;
use App\Modules\Projects\Requests\UpdateIssueRequest;
use App\Modules\Projects\Requests\UpdateIssueStatusRequest;
use App\Modules\Projects\Services\IssueService;
use Inertia\Inertia;
use Inertia\Response;

class IssueController extends Controller
{
    public function __construct(
        protected IssueService $service,
    ) {}

    public function store(StoreIssueRequest $request, Project $project)
    {
        $data = $request->validated();
        $data['reporter_id'] = $request->user()->id;

        $this->service->create($project, $data);

        return back()->with('success', 'Issue created.');
    }

    public function edit(Project $project, Issue $issue): Response
    {
        return Inertia::render('Projects/Issues/Edit', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
            ],
            'issue' => [
                'id' => $issue->id,
                'code' => $issue->code,
                'title' => $issue->title,
                'description' => $issue->description,
                'type' => $issue->type,
                'status' => $issue->status,
                'priority' => $issue->priority,
                'assignee_id' => $issue->assignee_id,
                'due_date' => $issue->due_date?->format('Y-m-d'),
            ],
            'comments' => $issue->comments()->with('user:id,name')->get()->map(fn (IssueComment $c) => [
                'id' => $c->id,
                'body' => $c->body,
                'author' => $c->user?->name ?? 'Deleted user',
                'created_at_formatted' => $c->created_at?->format('d M Y H:i'),
            ]),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateIssueRequest $request, Project $project, Issue $issue)
    {
        $this->service->update($issue, $request->validated());

        return redirect()->route('projects.show', $project)->with('success', 'Issue updated.');
    }

    public function updateStatus(UpdateIssueStatusRequest $request, Project $project, Issue $issue)
    {
        $this->service->updateStatus($issue, $request->validated()['status']);

        return back()->with('success', 'Issue moved.');
    }

    public function destroy(Project $project, Issue $issue)
    {
        $this->service->delete($issue);

        return redirect()->route('projects.show', $project)->with('success', 'Issue deleted.');
    }
}
