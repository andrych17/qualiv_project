<?php

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\IssueComment;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Requests\StoreIssueCommentRequest;
use App\Modules\Projects\Services\IssueCommentService;

class IssueCommentController extends Controller
{
    public function __construct(
        protected IssueCommentService $service,
    ) {}

    public function store(StoreIssueCommentRequest $request, Project $project, Issue $issue)
    {
        $this->service->create($issue, $request->user()->id, $request->validated()['body']);

        return back()->with('success', 'Comment added.');
    }

    public function destroy(Project $project, Issue $issue, IssueComment $comment)
    {
        $this->service->delete($comment);

        return back()->with('success', 'Comment deleted.');
    }
}
