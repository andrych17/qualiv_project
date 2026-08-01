<?php

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

class IssueService
{
    /** @param  array<string, mixed>  $data */
    public function create(Project $project, array $data): Issue
    {
        return DB::transaction(function () use ($project, $data) {
            // Row-locked per-project sequence (PRJ-1, PRJ-2, ...) so two concurrent
            // creates on the same project can't allocate the same issue number.
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $seq = $locked->next_issue_seq;
            $locked->update(['next_issue_seq' => $seq + 1]);

            $data['project_id'] = $project->id;
            $data['code'] = "{$locked->code}-{$seq}";

            return Issue::query()->create($data);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Issue $issue, array $data): Issue
    {
        $issue->update($data);

        return $issue->refresh();
    }

    public function updateStatus(Issue $issue, string $status): Issue
    {
        $issue->update(['status' => $status]);

        return $issue->refresh();
    }

    public function delete(Issue $issue): void
    {
        $issue->delete();
    }
}
