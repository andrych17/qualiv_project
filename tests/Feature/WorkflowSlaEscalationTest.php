<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\WNE\Events\NotificationRequested;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowEscalationLog;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use App\Modules\WNE\Models\WrkflowSlaRule;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Models\WrkflowVersion;
use App\Modules\WNE\Services\SlaEscalationService;
use App\Modules\WNE\Services\WorkflowDefinitionService;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3F — SLA & Escalation Engine. Covers `due_at` computation
 * (step-level rule wins over a version-level default, no rule → NULL and
 * never swept), the three escalation actions (only `reassign_to_role`
 * mutates assignment — the other two are additive), escalate-once-per-attempt,
 * and the recovery-restarts-the-clock interaction with §3C's stuck-step sweep.
 */
class WorkflowSlaEscalationTest extends TestCase
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

    private function instanceStepFor($instance, WrkflowStep $step): ?WrkflowInstanceStep
    {
        return WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $step->id)->first();
    }

    public function test_due_at_is_computed_from_the_matching_step_level_sla_rule_at_step_start(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            [, $version] = $this->publishedDefinition('demo.sla_step_rule');
            $entry = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_TASK, 'is_entry_step' => true]);
            WrkflowSlaRule::query()->create(['step_id' => $entry->id, 'sla_hours' => 4, 'escalation_action' => WrkflowSlaRule::ACTION_NOTIFY_ROLE, 'escalation_target' => 'ops']);

            $base = Carbon::parse('2026-01-05 08:00:00');
            $this->travelTo($base);

            $instance = app(WorkflowService::class)->start('demo.sla_step_rule', null, null, []);
            $step = $this->instanceStepFor($instance, $entry);

            $this->assertNotNull($step->due_at);
            $this->assertTrue($step->due_at->equalTo($base->copy()->addHours(4)));
        });
    }

    public function test_step_level_rule_wins_over_version_level_default(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            [, $version] = $this->publishedDefinition('demo.sla_precedence');
            $entry = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_TASK, 'is_entry_step' => true]);

            WrkflowSlaRule::query()->create(['version_id' => $version->id, 'sla_hours' => 24, 'escalation_action' => WrkflowSlaRule::ACTION_NOTIFY_ROLE, 'escalation_target' => 'ops']);
            WrkflowSlaRule::query()->create(['step_id' => $entry->id, 'sla_hours' => 2, 'escalation_action' => WrkflowSlaRule::ACTION_NOTIFY_ROLE, 'escalation_target' => 'ops']);

            $base = Carbon::parse('2026-01-05 08:00:00');
            $this->travelTo($base);

            $instance = app(WorkflowService::class)->start('demo.sla_precedence', null, null, []);
            $step = $this->instanceStepFor($instance, $entry);

            $this->assertTrue($step->due_at->equalTo($base->copy()->addHours(2)), 'the step-specific rule (2h) must win over the version default (24h)');
        });
    }

    public function test_a_step_with_no_matching_rule_gets_a_null_due_at_and_is_never_swept(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            [, $version] = $this->publishedDefinition('demo.sla_no_rule');
            $entry = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_TASK, 'is_entry_step' => true]);

            $instance = app(WorkflowService::class)->start('demo.sla_no_rule', null, null, []);
            $step = $this->instanceStepFor($instance, $entry);

            $this->assertNull($step->due_at);

            $this->travelTo(now()->addYears(5));
            $escalated = app(SlaEscalationService::class)->escalateBreachedSteps();

            $this->assertSame(0, $escalated);
            $this->assertSame(0, WrkflowEscalationLog::query()->count());
        });
    }

    public function test_reassign_to_role_escalation_replaces_assignment_logs_and_fires_notification(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $originalAssignee = $this->adminId();
            [, $version] = $this->publishedDefinition('demo.sla_reassign');
            $entry = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $originalAssignee]],
            ]);
            $rule = WrkflowSlaRule::query()->create(['step_id' => $entry->id, 'sla_hours' => 1, 'escalation_action' => WrkflowSlaRule::ACTION_REASSIGN_TO_ROLE, 'escalation_target' => 'ops_lead']);

            $base = Carbon::parse('2026-01-05 08:00:00');
            $this->travelTo($base);
            $instance = app(WorkflowService::class)->start('demo.sla_reassign', null, null, []);
            $step = $this->instanceStepFor($instance, $entry);
            $this->assertSame($originalAssignee, $step->assigned_to);

            $this->travelTo($base->copy()->addHours(2));
            $escalated = app(SlaEscalationService::class)->escalateBreachedSteps();

            $this->assertSame(1, $escalated);
            $step->refresh();
            $this->assertNull($step->assigned_to);
            $this->assertSame('ops_lead', $step->assigned_role);

            $log = WrkflowEscalationLog::query()->where('instance_step_id', $step->id)->firstOrFail();
            $this->assertSame($rule->id, $log->sla_rule_id);
            $this->assertSame('ops_lead', $log->escalated_to_role);

            Event::assertDispatched(NotificationRequested::class, fn (NotificationRequested $e) => $e->category === 'wne.sla_breach'
                && $e->recipient === ['type' => 'role', 'role' => 'ops_lead']);
        });
    }

    public function test_notify_role_escalation_is_additive_and_never_touches_assignment(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $originalAssignee = $this->adminId();
            [, $version] = $this->publishedDefinition('demo.sla_additive');
            $step = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $originalAssignee]],
            ]);
            WrkflowSlaRule::query()->create(['step_id' => $step->id, 'sla_hours' => 1, 'escalation_action' => WrkflowSlaRule::ACTION_NOTIFY_ROLE, 'escalation_target' => 'ops']);

            $base = Carbon::parse('2026-01-05 08:00:00');
            $this->travelTo($base);
            $instance = app(WorkflowService::class)->start('demo.sla_additive', null, null, []);
            $instanceStep = $this->instanceStepFor($instance, $step);

            $this->travelTo($base->copy()->addHours(2));
            $escalated = app(SlaEscalationService::class)->escalateBreachedSteps();
            $this->assertSame(1, $escalated);

            $instanceStep->refresh();
            $this->assertSame($originalAssignee, $instanceStep->assigned_to, 'notify_role must not touch the original assignee');
            $this->assertNull($instanceStep->assigned_role);

            $log = WrkflowEscalationLog::query()->where('instance_step_id', $instanceStep->id)->firstOrFail();
            $this->assertSame('ops', $log->escalated_to_role);

            Event::assertDispatched(NotificationRequested::class, fn (NotificationRequested $e) => $e->recipient === ['type' => 'role', 'role' => 'ops']);
        });
    }

    public function test_notify_manager_of_assignee_leaves_the_log_target_unresolved_but_carries_it_in_the_event(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $originalAssignee = $this->adminId();
            [, $version] = $this->publishedDefinition('demo.sla_manager_notify');
            $step = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $originalAssignee]],
            ]);
            WrkflowSlaRule::query()->create(['step_id' => $step->id, 'sla_hours' => 1, 'escalation_action' => WrkflowSlaRule::ACTION_NOTIFY_MANAGER_OF_ASSIGNEE, 'escalation_target' => null]);

            $base = Carbon::parse('2026-01-05 08:00:00');
            $this->travelTo($base);
            $instance = app(WorkflowService::class)->start('demo.sla_manager_notify', null, null, []);
            $instanceStep = $this->instanceStepFor($instance, $step);

            $this->travelTo($base->copy()->addHours(2));
            app(SlaEscalationService::class)->escalateBreachedSteps();

            $instanceStep->refresh();
            $this->assertSame($originalAssignee, $instanceStep->assigned_to);

            $log = WrkflowEscalationLog::query()->where('instance_step_id', $instanceStep->id)->firstOrFail();
            $this->assertNull($log->escalated_to_role);
            $this->assertNull($log->escalated_to_user_id);

            Event::assertDispatched(NotificationRequested::class, fn (NotificationRequested $e) => $e->recipient === ['type' => 'manager_of_user', 'user_id' => $originalAssignee]);
        });
    }

    /**
     * The correctness case flagged in review: recoverStuckSteps() (§3C) bumps attempt and
     * resets started_at on the SAME instance_step row. If due_at weren't recomputed, a
     * recovered step would stay permanently "breached" from its old due_at, and an
     * escalate-once check keyed only on instance_step_id would then suppress every future
     * escalation forever. Both halves are proven here: due_at moves into the future on
     * recovery, and a genuinely NEW breach after that still escalates (a second log row),
     * unsuppressed by the first attempt's already-logged escalation.
     */
    public function test_recovery_restarts_the_sla_clock_and_a_later_breach_escalates_again(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            [, $version] = $this->publishedDefinition('demo.sla_recovery');
            $entry = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $this->adminId()]],
            ]);
            WrkflowSlaRule::query()->create(['step_id' => $entry->id, 'sla_hours' => 1, 'escalation_action' => WrkflowSlaRule::ACTION_REASSIGN_TO_ROLE, 'escalation_target' => 'ops']);

            $base = Carbon::parse('2026-01-05 08:00:00');
            $this->travelTo($base);
            $workflow = app(WorkflowService::class);
            $escalation = app(SlaEscalationService::class);
            $instance = $workflow->start('demo.sla_recovery', null, null, []);
            $step = $this->instanceStepFor($instance, $entry);
            $this->assertTrue($step->due_at->equalTo($base->copy()->addHours(1)));

            // First breach — escalates once, and a second sweep at the same moment is a no-op.
            $this->travelTo($base->copy()->addHours(2));
            $this->assertSame(1, $escalation->escalateBreachedSteps());
            $this->assertSame(0, $escalation->escalateBreachedSteps());
            $this->assertSame(1, WrkflowEscalationLog::query()->where('instance_step_id', $step->id)->count());

            // Recovery restarts the clock: due_at must move into the future relative to the
            // new started_at, not stay pinned to the old (already-past) one.
            $this->travelTo($base->copy()->addHours(2)->addMinutes(5));
            $workflow->recoverStuckSteps(30);
            $step->refresh();
            $this->assertSame(2, $step->attempt);
            $this->assertTrue($step->due_at->equalTo($step->started_at->copy()->addHours(1)));
            $this->assertTrue($step->due_at->isFuture());

            // Not yet breached again — sweeping now must not escalate.
            $this->assertSame(0, $escalation->escalateBreachedSteps());

            // A later, genuine second breach on the recovered attempt must escalate again,
            // producing a SECOND log row rather than being suppressed by the first.
            $this->travelTo($base->copy()->addHours(4));
            $this->assertSame(1, $escalation->escalateBreachedSteps());
            $this->assertSame(2, WrkflowEscalationLog::query()->where('instance_step_id', $step->id)->count());
        });
    }

    public function test_workflow_definition_service_sla_rule_authoring_is_draft_only(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $definitions = app(WorkflowDefinitionService::class);
            $admin = $this->adminId();

            $definition = $definitions->create(['code' => 'demo.sla_authoring', 'name' => 'SLA Authoring'], $admin);
            $step = $definitions->addStep($definition, ['step_code' => 'entry', 'type' => WrkflowStep::TYPE_TASK, 'is_entry_step' => true]);

            $rule = $definitions->setStepSlaRule($step, 8, WrkflowSlaRule::ACTION_NOTIFY_ROLE, 'ops');
            $this->assertSame(8.0, (float) $rule->sla_hours);

            $definitions->publish($definition, $admin);
            $step->refresh(); // reload the now-stale cached `version` relation, same as a fresh request would

            $this->expectExceptionMessage('already been published');
            $definitions->setStepSlaRule($step, 16, WrkflowSlaRule::ACTION_NOTIFY_ROLE, 'ops');
        });
    }
}
