<?php

namespace Tests\Feature;

use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** WNE_SPECS.md §3B — Workflow Definition Builder, driven through the HTTP/Inertia surface. */
class WorkflowDefinitionBuilderTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_build_and_publish_a_two_step_workflow(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/wne/workflows')->assertOk()->assertInertia(fn ($page) => $page->component('WNE/Workflows/Index'));

        $this->post('/wne/workflows', [
            'code' => 'demo.expense_approval',
            'name' => 'Expense Approval',
            'description' => 'Manager approves expenses over a threshold.',
        ])->assertRedirect();

        $definitionId = null;
        $tenant->run(function () use (&$definitionId) {
            $definitionId = WrkflowDefinition::query()->where('code', 'demo.expense_approval')->firstOrFail()->id;
        });

        $this->get("/wne/workflows/{$definitionId}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WNE/Workflows/Edit')
                ->where('draftVersionNo', 1)
                ->where('publishedVersionNo', null)
            );

        // Publishing with zero steps must fail the graph validation (no entry step).
        $this->post("/wne/workflows/{$definitionId}/publish")->assertSessionHasErrors('steps');

        $this->post("/wne/workflows/{$definitionId}/steps", [
            'step_code' => 'submit',
            'type' => WrkflowStep::TYPE_TASK,
            'config' => ['assignee' => ['type' => 'payload_field', 'field' => 'submitter_id']],
            'is_entry_step' => true,
        ])->assertRedirect();

        $this->post("/wne/workflows/{$definitionId}/steps", [
            'step_code' => 'manager_approval',
            'type' => WrkflowStep::TYPE_APPROVAL,
            'config' => ['assignee' => ['type' => 'role', 'role' => 'manager']],
        ])->assertRedirect();

        [$submitId, $approvalId] = [null, null];
        $tenant->run(function () use (&$submitId, &$approvalId, $definitionId) {
            $definition = WrkflowDefinition::query()->findOrFail($definitionId);
            $draft = $definition->draftVersion();
            $submitId = $draft->steps()->where('step_code', 'submit')->firstOrFail()->id;
            $approvalId = $draft->steps()->where('step_code', 'manager_approval')->firstOrFail()->id;
        });

        // Publishing with an unreachable step (no transition yet) must be blocked.
        $this->post("/wne/workflows/{$definitionId}/publish")->assertSessionHasErrors('steps');

        $this->post("/wne/workflows/{$definitionId}/transitions", [
            'from_step_id' => $submitId,
            'to_step_id' => $approvalId,
        ])->assertRedirect();

        $this->post("/wne/workflows/{$definitionId}/publish")->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($definitionId) {
            $definition = WrkflowDefinition::query()->findOrFail($definitionId);
            $this->assertSame(WrkflowDefinition::STATUS_PUBLISHED, $definition->status);

            $version = $definition->currentPublishedVersion();
            $this->assertNotNull($version);
            $this->assertSame(1, $version->version_no);

            // §3C can now actually run an instance through what §3B just built.
            $instance = app(WorkflowService::class)->start('demo.expense_approval', null, null, ['submitter_id' => $definition->created_by]);
            $this->assertSame($version->id, $instance->definition_version_id);
        });

        $this->post("/wne/workflows/{$definitionId}/unpublish")->assertRedirect();

        $tenant->run(function () use ($definitionId) {
            $definition = WrkflowDefinition::query()->findOrFail($definitionId);
            $this->assertSame(WrkflowDefinition::STATUS_UNPUBLISHED, $definition->status);
            $this->assertNull($definition->currentPublishedVersion());

            // Unpublishing must never touch an already-running instance.
            $this->assertSame(1, WrkflowInstance::query()->where('status', WrkflowInstance::STATUS_RUNNING)->count());
        });
    }

    public function test_editing_a_published_workflow_forks_a_new_draft_without_touching_the_live_version(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->post('/wne/workflows', ['code' => 'demo.single_step', 'name' => 'Single Step'])->assertRedirect();

        $definitionId = null;
        $tenant->run(function () use (&$definitionId) {
            $definitionId = WrkflowDefinition::query()->where('code', 'demo.single_step')->firstOrFail()->id;
        });

        $this->post("/wne/workflows/{$definitionId}/steps", [
            'step_code' => 'only_step',
            'type' => WrkflowStep::TYPE_TASK,
            'is_entry_step' => true,
        ])->assertRedirect();

        $this->post("/wne/workflows/{$definitionId}/publish")->assertSessionDoesntHaveErrors();

        $v1Id = null;
        $tenant->run(function () use (&$v1Id, $definitionId) {
            $v1Id = WrkflowDefinition::query()->findOrFail($definitionId)->currentPublishedVersion()->id;
        });

        // Opening the builder again on an already-published definition must fork a new draft.
        $this->get("/wne/workflows/{$definitionId}/edit")
            ->assertInertia(fn ($page) => $page->where('draftVersionNo', 2)->where('publishedVersionNo', 1));

        $this->post("/wne/workflows/{$definitionId}/steps", [
            'step_code' => 'extra_step',
            'type' => WrkflowStep::TYPE_TASK,
        ])->assertRedirect();

        $tenant->run(function () use ($v1Id, $definitionId) {
            // v1's steps are untouched — the new step landed on the draft (v2), not on live history.
            $this->assertSame(1, WrkflowStep::query()->where('version_id', $v1Id)->count());

            $definition = WrkflowDefinition::query()->findOrFail($definitionId);
            $this->assertSame($v1Id, $definition->currentPublishedVersion()->id);
        });
    }
}
