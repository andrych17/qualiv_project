<?php

namespace App\Modules\WNE\Services;

use App\Modules\WNE\Events\NotificationRequested;
use App\Modules\WNE\Models\WrkflowEscalationLog;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use App\Modules\WNE\Models\WrkflowSlaRule;
use Illuminate\Support\Facades\DB;

/**
 * §3F — sweeps steps that breached their SLA `due_at` (populated by
 * WorkflowService::beginStep()/recoverStuckSteps() from a matching
 * wrkflow_sla_rules row) and escalates each exactly once per attempt.
 *
 * Escalation is additive by default — the original assignee is never
 * silently dropped — except `reassign_to_role`, which the spec calls out
 * by name as the one action that actually replaces ownership, and
 * `fail_step` (§3G), which replaces the step's own status outright — a
 * `wait_for_callback` step whose external callback never arrived within its
 * SLA `due_at` is "treated as a failure," per WNE_SPECS.md §3G, using this
 * exact sweep rather than a second timeout mechanism.
 */
class SlaEscalationService
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function escalateBreachedSteps(): int
    {
        $breached = WrkflowInstanceStep::query()
            ->whereIn('status', [WrkflowInstanceStep::STATUS_PENDING, WrkflowInstanceStep::STATUS_IN_PROGRESS, WrkflowInstanceStep::STATUS_WAITING_EXTERNAL])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereHas('instance', fn ($q) => $q->where('status', WrkflowInstance::STATUS_RUNNING))
            ->get();

        $escalated = 0;

        foreach ($breached as $instanceStep) {
            if ($this->escalateStep($instanceStep)) {
                $escalated++;
            }
        }

        return $escalated;
    }

    private function escalateStep(WrkflowInstanceStep $instanceStep): bool
    {
        return DB::transaction(function () use ($instanceStep) {
            $instanceStep = WrkflowInstanceStep::query()->whereKey($instanceStep->id)->lockForUpdate()->first();

            if (! $instanceStep
                || ! in_array($instanceStep->status, [WrkflowInstanceStep::STATUS_PENDING, WrkflowInstanceStep::STATUS_IN_PROGRESS, WrkflowInstanceStep::STATUS_WAITING_EXTERNAL], true)
                || ! $instanceStep->due_at
                || $instanceStep->due_at->isFuture()
            ) {
                return false; // resolved, or recovered past due_at, between the select above and this lock
            }

            // Escalate-once-per-attempt: a prior escalation logged before this attempt's
            // started_at belongs to an earlier attempt (recoverStuckSteps() restarts the SLA
            // clock and reuses the same instance_step row) and must not suppress this one.
            $alreadyEscalatedThisAttempt = WrkflowEscalationLog::query()
                ->where('instance_step_id', $instanceStep->id)
                ->where('escalated_at', '>=', $instanceStep->started_at)
                ->exists();

            if ($alreadyEscalatedThisAttempt) {
                return false;
            }

            $rule = WrkflowSlaRule::resolveFor($instanceStep->step);

            if (! $rule) {
                return false; // due_at without a resolvable rule shouldn't happen, but stay defensive
            }

            $recipient = match ($rule->escalation_action) {
                WrkflowSlaRule::ACTION_REASSIGN_TO_ROLE, WrkflowSlaRule::ACTION_NOTIFY_ROLE => ['type' => 'role', 'role' => $rule->escalation_target],
                WrkflowSlaRule::ACTION_NOTIFY_MANAGER_OF_ASSIGNEE => ['type' => 'manager_of_user', 'user_id' => $instanceStep->assigned_to],
                default => ['type' => 'role', 'role' => $rule->escalation_target],
            };

            if ($rule->escalation_action === WrkflowSlaRule::ACTION_REASSIGN_TO_ROLE) {
                $instanceStep->update(['assigned_role' => $rule->escalation_target, 'assigned_to' => null]);
            }

            if ($rule->escalation_action === WrkflowSlaRule::ACTION_FAIL_STEP) {
                $this->workflow->failStep(
                    $instanceStep,
                    "Step '{$instanceStep->step->step_code}' timed out — no callback received before its SLA due_at.",
                    null,
                );
            }

            WrkflowEscalationLog::query()->create([
                'instance_step_id' => $instanceStep->id,
                'sla_rule_id' => $rule->id,
                // Only a role-shaped target is resolvable to a stored column here — a
                // manager-of-assignee target is left for whatever eventually consumes
                // NotificationRequested to resolve; the descriptor still travels in the event.
                'escalated_to_role' => $recipient['type'] === 'role' ? $recipient['role'] : null,
                'escalated_at' => now(),
            ]);

            NotificationRequested::dispatch(
                category: 'wne.sla_breach',
                recipient: $recipient,
                payload: [
                    'instance_step_id' => $instanceStep->id,
                    'step_code' => $instanceStep->step->step_code,
                    'due_at' => $instanceStep->due_at->toIso8601String(),
                    'escalation_action' => $rule->escalation_action,
                ],
                subjectType: $instanceStep->instance->subject_type,
                subjectId: $instanceStep->instance->subject_id,
                subject: "SLA breach: step '{$instanceStep->step->step_code}'",
                body: "Step '{$instanceStep->step->step_code}' on workflow instance #{$instanceStep->instance_id} was due at {$instanceStep->due_at->toIso8601String()} and has been escalated.",
            );

            return true;
        });
    }
}
