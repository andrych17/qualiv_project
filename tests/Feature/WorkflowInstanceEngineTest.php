<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\WNE\Exceptions\WorkflowEngineException;
use App\Modules\WNE\Models\WrkflowAuditLog;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Models\WrkflowVersion;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** WNE_SPECS.md §3C — the durable execution core, exercised directly against WorkflowService (§3B's authoring UI doesn't exist yet). */
class WorkflowInstanceEngineTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_two_step_sequential_workflow_runs_to_completion(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();
            $manager = User::factory()->create(['email' => 'manager@nusaevo.com']);
            $staff = User::factory()->create(['email' => 'staff@nusaevo.com']);

            $definition = WrkflowDefinition::query()->create([
                'code' => 'demo.two_step',
                'name' => 'Two Step Demo',
                'status' => WrkflowDefinition::STATUS_PUBLISHED,
            ]);
            $version = WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => 1, 'published_by' => $admin->id]);

            $submit = WrkflowStep::query()->create([
                'version_id' => $version->id,
                'step_code' => 'submit',
                'type' => WrkflowStep::TYPE_TASK,
                'config' => ['assignee' => ['type' => 'payload_field', 'field' => 'submitter_id']],
                'is_entry_step' => true,
            ]);
            $approve = WrkflowStep::query()->create([
                'version_id' => $version->id,
                'step_code' => 'manager_approval',
                'type' => WrkflowStep::TYPE_APPROVAL,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $manager->id]],
            ]);
            $submit->outgoingTransitions()->create(['to_step_id' => $approve->id]);

            $workflow = app(WorkflowService::class);

            $instance = $workflow->start('demo.two_step', 'demo.subject', 501, ['submitter_id' => $staff->id]);

            $this->assertSame(WrkflowInstance::STATUS_RUNNING, $instance->status);
            $this->assertNotEmpty($instance->uuid);

            $entryStep = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $submit->id)->firstOrFail();
            $this->assertSame(WrkflowInstanceStep::STATUS_IN_PROGRESS, $entryStep->status);
            $this->assertSame($staff->id, $entryStep->assigned_to);

            // Idempotent double-submit: same decision replayed must not duplicate the advance.
            $workflow->completeTask($entryStep, 'submitted', null, $staff->id);
            $workflow->completeTask($entryStep->refresh(), 'submitted', null, $staff->id);

            $approvalStep = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $approve->id)->firstOrFail();
            $this->assertSame(WrkflowInstanceStep::STATUS_IN_PROGRESS, $approvalStep->status);
            $this->assertSame($manager->id, $approvalStep->assigned_to);
            $this->assertSame(1, WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $approve->id)->count());

            $workflow->completeTask($approvalStep, 'approve', 'looks good', $manager->id);

            $instance->refresh();
            $this->assertSame(WrkflowInstance::STATUS_COMPLETED, $instance->status);
            $this->assertNotNull($instance->ended_at);

            $this->assertSame(
                [
                    WrkflowAuditLog::EVENT_INSTANCE_STARTED,
                    WrkflowAuditLog::EVENT_STEP_COMPLETED,
                    WrkflowAuditLog::EVENT_STEP_COMPLETED,
                    WrkflowAuditLog::EVENT_INSTANCE_COMPLETED,
                ],
                WrkflowAuditLog::query()->where('instance_id', $instance->id)->orderBy('id')->pluck('event_type')->all()
            );
        });
    }

    public function test_start_pins_to_the_published_version_not_a_newer_unpublished_draft(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();

            $definition = WrkflowDefinition::query()->create([
                'code' => 'demo.versioned',
                'name' => 'Versioned',
                'status' => WrkflowDefinition::STATUS_PUBLISHED,
            ]);
            $v1 = WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => 1, 'published_by' => $admin->id]);
            WrkflowStep::query()->create([
                'version_id' => $v1->id,
                'step_code' => 'only_step',
                'type' => WrkflowStep::TYPE_TASK,
                'is_entry_step' => true,
            ]);

            // §3B in progress: a higher version_no exists but was never published (published_by NULL).
            $v2 = WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => 2]);
            WrkflowStep::query()->create([
                'version_id' => $v2->id,
                'step_code' => 'draft_only_step',
                'type' => WrkflowStep::TYPE_WEBHOOK_CALL, // unsupported — proves start() never touched v2's steps
                'is_entry_step' => true,
            ]);

            $resolved = app(WorkflowService::class)->resolvePublishedVersion('demo.versioned');
            $this->assertSame($v1->id, $resolved->id);

            $instance = app(WorkflowService::class)->start('demo.versioned', null, null, []);
            $this->assertSame($v1->id, $instance->definition_version_id);
        });
    }

    public function test_start_rejects_unpublished_definition(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            WrkflowDefinition::query()->create([
                'code' => 'demo.draft_only',
                'name' => 'Still a draft',
                'status' => WrkflowDefinition::STATUS_DRAFT,
            ]);

            $this->expectException(WorkflowEngineException::class);
            app(WorkflowService::class)->start('demo.draft_only', null, null, []);
        });
    }

    public function test_unsupported_step_type_throws_instead_of_silently_completing(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();

            $definition = WrkflowDefinition::query()->create([
                'code' => 'demo.webhook_only',
                'name' => 'Webhook Only',
                'status' => WrkflowDefinition::STATUS_PUBLISHED,
            ]);
            $version = WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => 1, 'published_by' => $admin->id]);
            WrkflowStep::query()->create([
                'version_id' => $version->id,
                'step_code' => 'call_out',
                'type' => WrkflowStep::TYPE_WEBHOOK_CALL,
                'is_entry_step' => true,
            ]);

            $this->expectException(WorkflowEngineException::class);
            app(WorkflowService::class)->start('demo.webhook_only', null, null, []);
        });
    }

    public function test_cancel_stops_a_running_instance_and_its_open_step(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();

            $definition = WrkflowDefinition::query()->create([
                'code' => 'demo.cancelable',
                'name' => 'Cancelable',
                'status' => WrkflowDefinition::STATUS_PUBLISHED,
            ]);
            $version = WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => 1, 'published_by' => $admin->id]);
            WrkflowStep::query()->create([
                'version_id' => $version->id,
                'step_code' => 'only_step',
                'type' => WrkflowStep::TYPE_TASK,
                'is_entry_step' => true,
            ]);

            $workflow = app(WorkflowService::class);
            $instance = $workflow->start('demo.cancelable', null, null, []);

            $workflow->cancel($instance, $admin->id, 'no longer needed');

            $instance->refresh();
            $this->assertSame(WrkflowInstance::STATUS_CANCELLED, $instance->status);
            $this->assertSame(
                WrkflowInstanceStep::STATUS_CANCELLED,
                WrkflowInstanceStep::query()->where('instance_id', $instance->id)->first()->status
            );
        });
    }

    public function test_recovery_sweep_re_drives_a_stuck_in_progress_step(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();

            $definition = WrkflowDefinition::query()->create([
                'code' => 'demo.recoverable',
                'name' => 'Recoverable',
                'status' => WrkflowDefinition::STATUS_PUBLISHED,
            ]);
            $version = WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => 1, 'published_by' => $admin->id]);
            WrkflowStep::query()->create([
                'version_id' => $version->id,
                'step_code' => 'only_step',
                'type' => WrkflowStep::TYPE_TASK,
                'is_entry_step' => true,
            ]);

            $workflow = app(WorkflowService::class);
            $instance = $workflow->start('demo.recoverable', null, null, []);

            $stepRow = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->firstOrFail();
            $stepRow->update(['started_at' => now()->subHour()]);

            $recovered = $workflow->recoverStuckSteps(30);

            $this->assertSame(1, $recovered);
            $stepRow->refresh();
            $this->assertSame(2, $stepRow->attempt);
            $this->assertSame(
                WrkflowInstanceStep::idempotencyKey($instance->id, $stepRow->step_id, 2),
                $stepRow->idempotency_key
            );
        });
    }
}
