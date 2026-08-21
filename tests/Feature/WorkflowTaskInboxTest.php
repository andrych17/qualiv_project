<?php

namespace Tests\Feature;

use App\Models\TenantUserLookup;
use App\Models\User;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Models\WrkflowVersion;
use App\Modules\WNE\Services\TaskInboxService;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3H — My Approvals / Task Inbox. Covers the assignability predicate (direct /
 * role / team-stub / unassigned — WrkflowInstanceStep::isActionableBy() and its SQL mirror),
 * the HTTP surface (index listing, completeTask()'s new actor-authorization guard), and that
 * only human-actionable open steps on running instances ever show up.
 */
class WorkflowTaskInboxTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function adminId(): int
    {
        return User::query()->where('email', 'admin@nusaevo.com')->value('id');
    }

    /** @return array{0: WrkflowDefinition, 1: WrkflowVersion} */
    private function publishedDefinition(string $code): array
    {
        $definition = WrkflowDefinition::query()->create(['code' => $code, 'name' => $code, 'status' => WrkflowDefinition::STATUS_PUBLISHED]);
        $version = WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => 1, 'published_by' => $this->adminId()]);

        return [$definition, $version];
    }

    private function runningInstance(WrkflowVersion $version): WrkflowInstance
    {
        return WrkflowInstance::query()->create([
            'definition_version_id' => $version->id,
            'status' => WrkflowInstance::STATUS_RUNNING,
            'payload' => [],
            'started_at' => now(),
        ]);
    }

    /** A second user in the STAFF group (WNE menu rights, per SysConfigSeeder) — created after seeding, so grouped by hand rather than via the seeder's USER_GROUPS map. */
    private function createStaffUser(string $email): int
    {
        $user = User::factory()->create(['email' => $email, 'password' => 'password', 'email_verified_at' => now()]);
        $groupId = ConfigGroup::query()->where('code', 'STAFF')->value('id');
        ConfigGroupUser::query()->create(['group_id' => $groupId, 'group_code' => 'STAFF', 'user_id' => $user->id]);
        TenantUserLookup::query()->updateOrCreate(['email' => $email, 'tenant_id' => '001'], []);

        return $user->id;
    }

    public function test_is_actionable_by_covers_direct_role_team_and_unassigned_steps(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $other = $this->createStaffUser('staff@nusaevo.com');
            [, $version] = $this->publishedDefinition('demo.assignability');
            $directStep = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'direct', 'type' => WrkflowStep::TYPE_TASK]);
            $roleStep = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'role', 'type' => WrkflowStep::TYPE_TASK]);
            $teamStep = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'team', 'type' => WrkflowStep::TYPE_TASK]);
            $unassignedStep = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'unassigned', 'type' => WrkflowStep::TYPE_TASK]);
            $instanceId = $this->runningInstance($version)->id;

            $direct = WrkflowInstanceStep::query()->create(['instance_id' => $instanceId, 'step_id' => $directStep->id, 'status' => 'in_progress', 'attempt' => 1, 'idempotency_key' => 'k1', 'assigned_to' => $admin]);
            $role = WrkflowInstanceStep::query()->create(['instance_id' => $instanceId, 'step_id' => $roleStep->id, 'status' => 'in_progress', 'attempt' => 1, 'idempotency_key' => 'k2', 'assigned_role' => 'ops']);
            $team = WrkflowInstanceStep::query()->create(['instance_id' => $instanceId, 'step_id' => $teamStep->id, 'status' => 'in_progress', 'attempt' => 1, 'idempotency_key' => 'k3', 'assigned_team_id' => 7]);
            $unassigned = WrkflowInstanceStep::query()->create(['instance_id' => $instanceId, 'step_id' => $unassignedStep->id, 'status' => 'in_progress', 'attempt' => 1, 'idempotency_key' => 'k4']);

            $this->assertTrue($direct->isActionableBy($admin, []));
            $this->assertFalse($direct->isActionableBy($other, []));

            $this->assertTrue($role->isActionableBy($other, ['ops', 'other']));
            $this->assertFalse($role->isActionableBy($other, ['other']));

            // No Team model yet (§5) — a team-assigned step is open to anyone rather than disappearing.
            $this->assertTrue($team->isActionableBy($other, []));

            $this->assertTrue($unassigned->isActionableBy($other, []));

            // scopeAssignedToUser() is the SQL mirror of isActionableBy() above — same set of
            // rows must come back, or the inbox list and the completeTask() guard would drift.
            $visibleToAdmin = WrkflowInstanceStep::query()->assignedToUser($admin, [])->pluck('id')->sort()->values()->all();
            $this->assertSame([$direct->id, $team->id, $unassigned->id], $visibleToAdmin);

            $visibleToOpsMember = WrkflowInstanceStep::query()->assignedToUser($other, ['ops'])->pluck('id')->sort()->values()->all();
            $this->assertSame([$role->id, $team->id, $unassigned->id], $visibleToOpsMember);
        });
    }

    public function test_my_tasks_query_excludes_non_human_steps_and_non_running_instances(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            [, $version] = $this->publishedDefinition('demo.inbox_scope');

            $humanStep = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'human', 'type' => WrkflowStep::TYPE_TASK, 'is_entry_step' => true]);
            $conditionStep = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'auto', 'type' => WrkflowStep::TYPE_CONDITION]);

            $workflow = app(WorkflowService::class);
            $instance = $workflow->start('demo.inbox_scope', null, null, []);

            // A completed step and an auto-advance (condition) step must never surface as "my tasks."
            WrkflowInstanceStep::query()->create(['instance_id' => $instance->id, 'step_id' => $conditionStep->id, 'status' => 'completed', 'attempt' => 1, 'idempotency_key' => 'auto-1']);

            $ids = app(TaskInboxService::class)->myTasksQuery($admin)->pluck('step_id')->all();
            $this->assertContains($humanStep->id, $ids);
            $this->assertNotContains($conditionStep->id, $ids);

            // Cancel the instance — its still-open step must drop out of everyone's inbox.
            $workflow->cancel($instance, $admin);
            $ids = app(TaskInboxService::class)->myTasksQuery($admin)->pluck('step_id')->all();
            $this->assertNotContains($humanStep->id, $ids);
        });
    }

    public function test_urgency_first_ordering_and_sla_state_filter(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            [, $version] = $this->publishedDefinition('demo.urgency_order');
            $breachedStep = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'breached', 'type' => WrkflowStep::TYPE_TASK]);
            $onTrackStep = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'on_track', 'type' => WrkflowStep::TYPE_TASK]);
            $noSlaStep = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'no_sla', 'type' => WrkflowStep::TYPE_TASK]);

            $now = Carbon::parse('2026-02-01 12:00:00');
            $this->travelTo($now);
            $instanceId = $this->runningInstance($version)->id;

            WrkflowInstanceStep::query()->create(['instance_id' => $instanceId, 'step_id' => $onTrackStep->id, 'status' => 'in_progress', 'attempt' => 1, 'idempotency_key' => 'a', 'due_at' => $now->copy()->addDays(3)]);
            WrkflowInstanceStep::query()->create(['instance_id' => $instanceId, 'step_id' => $noSlaStep->id, 'status' => 'in_progress', 'attempt' => 1, 'idempotency_key' => 'b']);
            WrkflowInstanceStep::query()->create(['instance_id' => $instanceId, 'step_id' => $breachedStep->id, 'status' => 'in_progress', 'attempt' => 1, 'idempotency_key' => 'c', 'due_at' => $now->copy()->subHour()]);

            $ordered = WrkflowInstanceStep::query()->urgencyFirst()->pluck('step_id')->all();
            $this->assertSame([$breachedStep->id, $onTrackStep->id, $noSlaStep->id], $ordered);

            $breachedOnly = WrkflowInstanceStep::query()->filterSlaState('breached')->pluck('step_id')->all();
            $this->assertSame([$breachedStep->id], $breachedOnly);
        });
    }

    public function test_completing_a_task_from_the_inbox_advances_the_workflow(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $admin = $this->adminId();
            [, $version] = $this->publishedDefinition('demo.inbox_complete');
            $entry = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $admin]],
            ]);
            $done = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'done', 'type' => WrkflowStep::TYPE_TASK]);
            $entry->outgoingTransitions()->create(['to_step_id' => $done->id]);

            app(WorkflowService::class)->start('demo.inbox_complete', null, null, []);
        });

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/wne/my-tasks')->assertOk()->assertInertia(fn ($page) => $page
            ->component('WNE/MyTasks/Index')
            ->has('tasks.data', 1)
            ->where('tasks.data.0.step_code', 'entry'));

        $stepId = null;
        $tenant->run(function () use (&$stepId) {
            $stepId = WrkflowInstanceStep::query()->where('status', 'in_progress')->firstOrFail()->id;
        });

        $this->post("/wne/my-tasks/{$stepId}/complete", ['decision' => 'approve'])->assertRedirect();

        $tenant->run(function () use ($stepId) {
            $this->assertSame('completed', WrkflowInstanceStep::query()->findOrFail($stepId)->status);
            $this->assertSame(1, WrkflowInstanceStep::query()->where('status', 'in_progress')->count());
        });
    }

    public function test_a_user_who_is_not_the_resolved_assignee_cannot_complete_the_task(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $stepId = null;
        $tenant->run(function () use (&$stepId) {
            $admin = $this->adminId();
            $this->createStaffUser('staff@nusaevo.com');
            [, $version] = $this->publishedDefinition('demo.inbox_forbidden');
            $entry = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $admin]],
            ]);

            $instance = app(WorkflowService::class)->start('demo.inbox_forbidden', null, null, []);
            $stepId = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $entry->id)->firstOrFail()->id;
        });

        $this->post('/login', ['email' => 'staff@nusaevo.com', 'password' => 'password']);

        $this->post("/wne/my-tasks/{$stepId}/complete", ['decision' => 'approve'])->assertSessionHasErrors('decision');

        $tenant->run(function () use ($stepId) {
            $this->assertSame('in_progress', WrkflowInstanceStep::query()->findOrFail($stepId)->status);
        });
    }

    public function test_a_role_assigned_task_is_actionable_by_any_group_member(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $stepId = null;
        $tenant->run(function () use (&$stepId) {
            $this->createStaffUser('staff@nusaevo.com');
            [, $version] = $this->publishedDefinition('demo.inbox_role');
            $entry = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'role', 'role' => 'STAFF']],
            ]);

            $instance = app(WorkflowService::class)->start('demo.inbox_role', null, null, []);
            $stepId = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $entry->id)->firstOrFail()->id;
        });

        $this->post('/login', ['email' => 'staff@nusaevo.com', 'password' => 'password']);

        $this->post("/wne/my-tasks/{$stepId}/complete", ['decision' => 'approve'])->assertRedirect()->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($stepId) {
            $this->assertSame('completed', WrkflowInstanceStep::query()->findOrFail($stepId)->status);
        });
    }

    public function test_an_unoffered_decision_is_rejected(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $stepId = null;
        $tenant->run(function () use (&$stepId) {
            $admin = $this->adminId();
            [, $version] = $this->publishedDefinition('demo.inbox_bad_decision');
            $entry = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $admin], 'decisions' => ['approve', 'reject']],
            ]);

            $instance = app(WorkflowService::class)->start('demo.inbox_bad_decision', null, null, []);
            $stepId = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $entry->id)->firstOrFail()->id;
        });

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->post("/wne/my-tasks/{$stepId}/complete", ['decision' => 'yolo'])->assertSessionHasErrors('decision');

        $tenant->run(function () use ($stepId) {
            $this->assertSame('in_progress', WrkflowInstanceStep::query()->findOrFail($stepId)->status);
        });
    }
}
