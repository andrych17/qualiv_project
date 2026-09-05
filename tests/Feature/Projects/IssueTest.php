<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpProjects;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3C/§3D — Kanban board + issue CRUD: sequential per-project codes, status/assignee quick actions, the board's own stats/overdue calc. */
class IssueTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpProjects;
    use SetsUpTenant;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_crud_an_issue_and_codes_are_sequential_per_project(): void
    {
        $tenant = $this->loginAsProjectsAdmin();

        $projectId = null;
        $assigneeId = null;
        $tenant->run(function () use (&$projectId, &$assigneeId) {
            $projectId = $this->makeProject('PRJ', 'Kanban Test')->id;
            $assigneeId = User::factory()->create(['email' => 'assignee@nusaevo.com'])->id;
        });

        $this->post("/projects/{$projectId}/issues", [
            'title' => 'First issue', 'type' => 'task', 'status' => 'todo', 'priority' => 'medium',
        ])->assertRedirect();

        $this->post("/projects/{$projectId}/issues", [
            'title' => 'Second issue', 'type' => 'bug', 'status' => 'todo', 'priority' => 'high',
        ])->assertRedirect();

        $issueId = null;
        $tenant->run(function () use (&$issueId, $projectId) {
            $first = Issue::query()->where('title', 'First issue')->first();
            $second = Issue::query()->where('title', 'Second issue')->first();
            $this->assertSame('PRJ-1', $first->code);
            $this->assertSame('PRJ-2', $second->code);
            $this->assertSame(3, Project::query()->find($projectId)->next_issue_seq);
            $issueId = $first->id;
        });

        $this->get("/projects/{$projectId}/issues/{$issueId}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Issues/Edit')
                ->where('issue.title', 'First issue'));

        $this->put("/projects/{$projectId}/issues/{$issueId}", [
            'title' => 'First issue (renamed)', 'type' => 'task', 'status' => 'todo',
            'priority' => 'low', 'assignee_id' => $assigneeId, 'due_date' => '2026-12-01',
        ])->assertRedirect(route('projects.issues.edit', [$projectId, $issueId]));

        $tenant->run(function () use ($issueId, $assigneeId) {
            $issue = Issue::query()->find($issueId);
            $this->assertSame('First issue (renamed)', $issue->title);
            $this->assertSame($assigneeId, $issue->assignee_id);
        });

        $this->patch("/projects/{$projectId}/issues/{$issueId}/status", ['status' => 'in_progress'])->assertRedirect();
        $tenant->run(function () use ($issueId) {
            $this->assertSame('in_progress', Issue::query()->find($issueId)->status);
        });

        $this->patch("/projects/{$projectId}/issues/{$issueId}/assignee", ['assignee_id' => null])->assertRedirect();
        $tenant->run(function () use ($issueId) {
            $this->assertNull(Issue::query()->find($issueId)->assignee_id);
        });

        $this->delete("/projects/{$projectId}/issues/{$issueId}")->assertRedirect(route('projects.show', $projectId));
        $tenant->run(function () use ($issueId) {
            $this->assertNull(Issue::query()->find($issueId));
        });
    }

    public function test_visiting_the_issues_index_url_redirects_to_the_board(): void
    {
        $tenant = $this->loginAsProjectsAdmin();

        $projectId = null;
        $tenant->run(function () use (&$projectId) {
            $projectId = $this->makeProject()->id;
        });

        $this->get("/projects/{$projectId}/issues")->assertRedirect(route('projects.show', $projectId));
    }

    public function test_project_board_computes_stats_and_flags_overdue_issues(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00'));

        $tenant = $this->loginAsProjectsAdmin();

        $projectId = null;
        $tenant->run(function () use (&$projectId) {
            $project = $this->makeProject();
            $projectId = $project->id;

            $this->makeIssue($project, 'Todo overdue', ['status' => 'todo', 'due_date' => '2026-11-01']);
            $this->makeIssue($project, 'Todo future', ['status' => 'todo', 'due_date' => '2026-12-01']);
            $this->makeIssue($project, 'In progress', ['status' => 'in_progress']);
            $this->makeIssue($project, 'Done but overdue', ['status' => 'done', 'due_date' => '2026-11-01']);
        });

        $response = $this->get("/projects/{$projectId}")->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stats.total', 4)
            ->where('stats.todo', 2)
            ->where('stats.in_progress', 1)
            ->where('stats.done', 1)
            // "done" status is exempt from the overdue flag even with a past due date.
            ->where('stats.overdue', 1));

        $issues = collect($response->viewData('page')['props']['issues'])->keyBy('title');
        $this->assertTrue($issues['Todo overdue']['is_overdue']);
        $this->assertFalse($issues['Todo future']['is_overdue']);
        $this->assertFalse($issues['Done but overdue']['is_overdue']);
    }

    public function test_store_issue_validation_rejects_bad_fields(): void
    {
        $tenant = $this->loginAsProjectsAdmin();

        $projectId = null;
        $tenant->run(function () use (&$projectId) {
            $projectId = $this->makeProject()->id;
        });

        $this->post("/projects/{$projectId}/issues", [])->assertSessionHasErrors(['title', 'type', 'status', 'priority']);

        $this->post("/projects/{$projectId}/issues", [
            'title' => 'Bad assignee', 'type' => 'task', 'status' => 'todo', 'priority' => 'medium',
            'assignee_id' => 999999,
        ])->assertSessionHasErrors(['assignee_id']);

        $this->post("/projects/{$projectId}/issues", [
            'title' => 'Bad type', 'type' => 'epic', 'status' => 'todo', 'priority' => 'medium',
        ])->assertSessionHasErrors(['type']);
    }

    public function test_update_issue_status_and_assignee_validation_rejects_bad_values(): void
    {
        $tenant = $this->loginAsProjectsAdmin();

        $projectId = null;
        $issueId = null;
        $tenant->run(function () use (&$projectId, &$issueId) {
            $project = $this->makeProject();
            $projectId = $project->id;
            $issueId = $this->makeIssue($project)->id;
        });

        $this->patch("/projects/{$projectId}/issues/{$issueId}/status", ['status' => 'archived'])
            ->assertSessionHasErrors(['status']);

        $this->patch("/projects/{$projectId}/issues/{$issueId}/assignee", ['assignee_id' => 999999])
            ->assertSessionHasErrors(['assignee_id']);
    }

    /** Issue::scopeFilter exists (search/status/priority/assignee_id) but no controller calls it — the board fetches everything and the List view filters client-side. Exercised directly since no route reaches it. */
    public function test_issue_filter_scope_matches_by_search_status_priority_and_assignee(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $assignee = User::factory()->create(['email' => 'filter-assignee@nusaevo.com']);
            $project = $this->makeProject();

            $this->makeIssue($project, 'Findable bug', ['type' => 'bug', 'status' => 'todo', 'priority' => 'high', 'assignee_id' => $assignee->id]);
            $this->makeIssue($project, 'Other task', ['status' => 'done', 'priority' => 'low']);

            $this->assertSame(1, Issue::query()->filter(['search' => 'Findable'])->count());
            $this->assertSame(1, Issue::query()->filter(['status' => 'done'])->count());
            $this->assertSame(1, Issue::query()->filter(['priority' => 'high'])->count());
            $this->assertSame(1, Issue::query()->filter(['assignee_id' => $assignee->id])->count());
        });
    }
}
