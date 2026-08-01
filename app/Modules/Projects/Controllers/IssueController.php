<?php

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\IssueAttachment;
use App\Modules\Projects\Models\IssueComment;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Requests\StoreIssueAttachmentRequest;
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
            'attachments' => $issue->attachments()->with('user:id,name')->get()->map(fn (IssueAttachment $a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'previewable' => $a->isPreviewable(),
                'size' => $a->size,
                'uploader' => $a->user?->name ?? 'Deleted user',
                'created_at_formatted' => $a->created_at?->format('d M Y H:i'),
                'download_url' => route('projects.issues.attachments.download', [$project->id, $issue->id, $a->id]),
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

    public function storeAttachment(StoreIssueAttachmentRequest $request, Project $project, Issue $issue)
    {
        $this->service->attachFile($issue, $request->file('file'), $request->user()->id);

        return back()->with('success', 'Attachment uploaded.');
    }

    public function destroyAttachment(Project $project, Issue $issue, IssueAttachment $attachment)
    {
        // Guard against cross-issue attachment access through the nested route.
        abort_unless($attachment->issue_id === $issue->id, 404);

        $this->service->deleteAttachment($attachment);

        return back()->with('success', 'Attachment deleted.');
    }

    public function downloadAttachment(Project $project, Issue $issue, IssueAttachment $attachment)
    {
        abort_unless($attachment->issue_id === $issue->id, 404);

        return $this->service->download($attachment);
    }
}
