<?php

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\IssueComment;

class IssueCommentService
{
    public function create(Issue $issue, int $userId, string $body): IssueComment
    {
        return $issue->comments()->create([
            'user_id' => $userId,
            'body' => $body,
        ]);
    }

    public function delete(IssueComment $comment): void
    {
        $comment->delete();
    }
}
