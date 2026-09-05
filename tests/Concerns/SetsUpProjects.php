<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\Project;

/** Shared bootstrap for Projects module tests — plan activation, admin login, and fixtures. */
trait SetsUpProjects
{
    protected function loginAsProjectsAdmin(): Tenant
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        return $tenant;
    }

    protected function makeProject(string $code = 'PRJ', string $name = 'Website Revamp'): Project
    {
        // next_issue_seq defaults to 1 at the DB level, but Eloquent doesn't re-fetch that
        // default into the in-memory model after create() — set it explicitly so callers
        // (e.g. makeIssue()) can rely on $project->next_issue_seq being accurate.
        return Project::query()->create(['code' => $code, 'name' => $name, 'status' => 'active', 'next_issue_seq' => 1]);
    }

    /** Mirrors IssueService::create()'s sequencing (minus the row lock, unneeded for a single-process fixture) so repeated calls against the same $project don't collide on the unique `code` column. */
    protected function makeIssue(Project $project, string $title = 'Fix layout bug', array $attrs = []): Issue
    {
        $seq = $project->next_issue_seq;
        $project->update(['next_issue_seq' => $seq + 1]);

        return Issue::query()->create([
            'project_id' => $project->id,
            'code' => $project->code.'-'.$seq,
            'title' => $title,
            'type' => 'task',
            'status' => 'todo',
            'priority' => 'medium',
            ...$attrs,
        ]);
    }
}
