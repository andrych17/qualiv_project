<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpProjects;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3A/§3B — Project registry: index/filter, CRUD, code-uniqueness validation, bulk destroy. */
class ProjectTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpProjects;
    use SetsUpTenant;

    public function test_admin_can_crud_a_project(): void
    {
        $tenant = $this->loginAsProjectsAdmin();

        $leadId = null;
        $tenant->run(function () use (&$leadId) {
            $leadId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
        });

        $this->get('/projects')->assertOk()->assertInertia(fn ($page) => $page->component('Projects/Projects/Index'));
        $this->get('/projects/create')->assertOk()->assertInertia(fn ($page) => $page->component('Projects/Projects/Create'));

        $this->post('/projects', [
            'code' => 'web-revamp',
            'name' => 'Website Revamp',
            'status' => 'planning',
            'lead_id' => $leadId,
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-01',
        ])->assertRedirect(route('projects.index'));

        $projectId = null;
        $tenant->run(function () use (&$projectId, $leadId) {
            $project = Project::query()->where('name', 'Website Revamp')->first();
            $this->assertNotNull($project);
            // prepareForValidation uppercases the code before it's persisted.
            $this->assertSame('WEB-REVAMP', $project->code);
            $this->assertSame($leadId, $project->lead_id);
            $projectId = $project->id;
        });

        $this->get("/projects/{$projectId}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Projects/Edit')
                ->where('project.code', 'WEB-REVAMP')
                ->where('project.start_date', '2026-11-01'));

        $this->put("/projects/{$projectId}", [
            'code' => 'WEB-REVAMP',
            'name' => 'Website Revamp v2',
            'status' => 'active',
        ])->assertRedirect(route('projects.index'));

        $tenant->run(function () use ($projectId) {
            $this->assertSame('Website Revamp v2', Project::query()->find($projectId)->name);
        });

        $this->get("/projects/{$projectId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Projects/Show')
                ->where('project.name', 'Website Revamp v2')
                ->where('stats.total', 0));

        $this->delete("/projects/{$projectId}")->assertRedirect(route('projects.index'));
        $tenant->run(function () use ($projectId) {
            $this->assertNull(Project::query()->find($projectId));
        });
    }

    public function test_project_index_filters_sorts_and_bulk_destroys(): void
    {
        $tenant = $this->loginAsProjectsAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makeProject('ALPHA', 'Alpha Project')->id;
            $beta = $this->makeProject('BETA', 'Beta Project');
            $beta->update(['status' => 'on_hold']);
            $ids[] = $beta->id;
        });

        $this->get('/projects?search=Alpha')->assertOk()
            ->assertInertia(fn ($page) => $page->has('projects.data', 1)->where('projects.data.0.code', 'ALPHA'));

        $this->get('/projects?status=on_hold')->assertOk()
            ->assertInertia(fn ($page) => $page->has('projects.data', 1)->where('projects.data.0.code', 'BETA'));

        $this->get('/projects?sort=code&direction=desc&per_page=5')->assertOk()
            ->assertInertia(fn ($page) => $page->where('projects.data.0.code', 'BETA'));

        $this->delete('/projects/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, Project::query()->whereIn('id', $ids)->count());
        });

        $this->delete('/projects/bulk-destroy', ['ids' => []])->assertSessionHasErrors(['ids']);
    }

    public function test_store_and_update_project_validation_rejects_duplicate_code_and_bad_dates(): void
    {
        $tenant = $this->loginAsProjectsAdmin();

        $this->post('/projects', [])->assertSessionHasErrors(['code', 'name', 'status']);

        $existingId = null;
        $tenant->run(function () use (&$existingId) {
            $existingId = $this->makeProject('DUPE', 'First')->id;
        });

        $this->post('/projects', [
            'code' => 'dupe',
            'name' => 'Second',
            'status' => 'active',
        ])->assertSessionHasErrors(['code']);

        $this->post('/projects', [
            'code' => 'BAD-DATES',
            'name' => 'Bad Dates',
            'status' => 'active',
            'start_date' => '2026-12-01',
            'end_date' => '2026-11-01',
        ])->assertSessionHasErrors(['end_date']);

        // Update: keeping the record's own code is fine; colliding with another project's isn't.
        $otherId = null;
        $tenant->run(function () use (&$otherId) {
            $otherId = $this->makeProject('OTHER', 'Other')->id;
        });

        $this->put("/projects/{$otherId}", [
            'code' => 'OTHER',
            'name' => 'Other (renamed)',
            'status' => 'active',
        ])->assertRedirect(route('projects.index'));

        $this->put("/projects/{$otherId}", [
            'code' => 'DUPE',
            'name' => 'Other',
            'status' => 'active',
        ])->assertSessionHasErrors(['code']);
    }
}
