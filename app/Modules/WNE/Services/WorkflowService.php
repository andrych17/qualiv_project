<?php

namespace App\Modules\WNE\Services;

use App\Modules\WNE\Events\WorkflowInstanceCompleted;
use App\Modules\WNE\Events\WorkflowInstanceFailed;
use App\Modules\WNE\Events\WorkflowInstanceStarted;
use App\Modules\WNE\Events\WorkflowStepCompleted;
use App\Modules\WNE\Exceptions\WorkflowEngineException;
use App\Modules\WNE\Models\WrkflowAuditLog;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use App\Modules\WNE\Models\WrkflowSlaRule;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Models\WrkflowTransition;
use App\Modules\WNE\Models\WrkflowVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §3C/§3D — the durable execution core plus routing/branching. Everything
 * here operates purely on persisted state (never queue-job memory) so a
 * crash mid-step is always safely resumable from the DB row alone (§5 "core
 * design decision").
 *
 * §3D scope: conditional branching (first matching transition wins, seq
 * order, NULL condition = default/else), parallel_split fan-out, and
 * parallel_join with an `all`/`any` join rule. 'approval'/'task' still need
 * a human decision via completeTask(); 'condition'/'parallel_split'/
 * 'parallel_join' have no human action, so the engine completes them itself
 * right after beginning them. webhook_call/wait_for_callback/notify remain
 * unsupported (§3G/§3I) and still throw rather than silently no-op.
 */
class WorkflowService
{
    public function start(
        string $code,
        ?string $subjectType,
        ?int $subjectId,
        array $payload,
        ?int $startedBy = null,
    ): WrkflowInstance {
        $version = $this->resolvePublishedVersion($code);

        $entryStep = $version->entryStep()->first();

        if (! $entryStep) {
            throw new WorkflowEngineException("Workflow '{$code}' version {$version->version_no} has no entry step configured.");
        }

        return DB::transaction(function () use ($version, $subjectType, $subjectId, $payload, $startedBy, $entryStep) {
            $instance = WrkflowInstance::query()->create([
                'definition_version_id' => $version->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'status' => WrkflowInstance::STATUS_RUNNING,
                'payload' => $payload,
                'started_by' => $startedBy,
                'started_at' => now(),
            ]);

            $this->log($instance, null, WrkflowAuditLog::EVENT_INSTANCE_STARTED, $startedBy);

            $this->beginStep($instance, $entryStep);

            WorkflowInstanceStarted::dispatch($instance->refresh());

            return $instance;
        });
    }

    /** §3E enforcement: always the highest version_no of a definition currently `published`, never a stale/unpublished one. */
    public function resolvePublishedVersion(string $code): WrkflowVersion
    {
        $definition = WrkflowDefinition::query()->where('code', $code)->first();

        if (! $definition) {
            throw new WorkflowEngineException("Workflow '{$code}' is not configured.");
        }

        $version = $definition->currentPublishedVersion();

        if (! $version) {
            throw new WorkflowEngineException("Workflow '{$code}' has no published version.");
        }

        return $version;
    }

    /** §3H: `WorkflowService::completeTask(taskId, decision, comment)` — idempotent past a terminal step (double-submit safe). */
    public function completeTask(WrkflowInstanceStep $instanceStep, string $decision, ?string $comment, ?int $actorId): WrkflowInstanceStep
    {
        return DB::transaction(function () use ($instanceStep, $decision, $comment, $actorId) {
            $instanceStep = WrkflowInstanceStep::query()->whereKey($instanceStep->id)->lockForUpdate()->firstOrFail();

            if (in_array($instanceStep->status, WrkflowInstanceStep::TERMINAL_STATUSES, true)) {
                return $instanceStep;
            }

            if (! in_array($instanceStep->step->type, WrkflowStep::HUMAN_TYPES, true)) {
                throw new WorkflowEngineException(
                    "Step '{$instanceStep->step->step_code}' is type '{$instanceStep->step->type}' and does not accept a manual decision."
                );
            }

            $this->finishStep($instanceStep, $decision, $comment, $actorId);

            return $instanceStep->refresh();
        });
    }

    public function failStep(WrkflowInstanceStep $instanceStep, ?string $reason, ?int $actorId): WrkflowInstanceStep
    {
        return DB::transaction(function () use ($instanceStep, $reason, $actorId) {
            $instanceStep = WrkflowInstanceStep::query()->whereKey($instanceStep->id)->lockForUpdate()->firstOrFail();

            if (in_array($instanceStep->status, WrkflowInstanceStep::TERMINAL_STATUSES, true)) {
                return $instanceStep;
            }

            $instanceStep->update([
                'status' => WrkflowInstanceStep::STATUS_FAILED,
                'comment' => $reason,
                'completed_at' => now(),
            ]);

            $this->log($instanceStep->instance, $instanceStep, WrkflowAuditLog::EVENT_STEP_FAILED, $actorId, ['reason' => $reason]);

            // No failure-transition routing in v1 — §3D can wire per-step failure
            // routing through this same advance() mechanism later.
            $this->failInstance($instanceStep->instance, $instanceStep);

            return $instanceStep;
        });
    }

    /** Manual cancel — "logged, never silent" (§3C rule). No-op past a terminal instance. */
    public function cancel(WrkflowInstance $instance, int $actorId, ?string $reason = null): WrkflowInstance
    {
        return DB::transaction(function () use ($instance, $actorId, $reason) {
            $instance = WrkflowInstance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();

            if ($instance->isTerminal()) {
                return $instance;
            }

            $instance->steps()->whereIn('status', WrkflowInstanceStep::OPEN_STATUSES)->update([
                'status' => WrkflowInstanceStep::STATUS_CANCELLED,
                'completed_at' => now(),
            ]);

            $instance->update(['status' => WrkflowInstance::STATUS_CANCELLED, 'ended_at' => now()]);

            $this->log($instance, null, WrkflowAuditLog::EVENT_INSTANCE_CANCELLED, $actorId, ['reason' => $reason]);

            return $instance;
        });
    }

    /**
     * Recovery sweep (§3C/§5): a step stuck `in_progress` past the grace period gets
     * re-driven on a fresh attempt — safe because every action is checked against
     * persisted state first, never re-run from a queue job's own memory.
     */
    public function recoverStuckSteps(int $graceMinutes = 30): int
    {
        $stuck = WrkflowInstanceStep::query()
            ->where('status', WrkflowInstanceStep::STATUS_IN_PROGRESS)
            ->where('started_at', '<', now()->subMinutes($graceMinutes))
            ->whereHas('instance', fn ($q) => $q->where('status', WrkflowInstance::STATUS_RUNNING))
            ->get();

        foreach ($stuck as $instanceStep) {
            DB::transaction(function () use ($instanceStep) {
                $instanceStep = WrkflowInstanceStep::query()->whereKey($instanceStep->id)->lockForUpdate()->first();

                if (! $instanceStep || $instanceStep->status !== WrkflowInstanceStep::STATUS_IN_PROGRESS) {
                    return; // already resolved between the select above and this lock
                }

                $startedAt = now();

                $instanceStep->update([
                    'attempt' => $instanceStep->attempt + 1,
                    'idempotency_key' => WrkflowInstanceStep::idempotencyKey($instanceStep->instance_id, $instanceStep->step_id, $instanceStep->attempt + 1),
                    'started_at' => $startedAt,
                    // §3F: the SLA clock restarts with the attempt — a step recovered after
                    // breaching must not keep an already-past due_at, or the escalation sweep's
                    // "already escalated since started_at" check (SlaEscalationService) would
                    // never fire again for it.
                    'due_at' => $this->resolveDueAt($instanceStep->step, $startedAt),
                ]);

                $this->log($instanceStep->instance, $instanceStep, WrkflowAuditLog::EVENT_STEP_RECOVERED, null, ['attempt' => $instanceStep->attempt]);
            });
        }

        return $stuck->count();
    }

    /**
     * Creates the persisted row for a step and performs its action.
     * 'approval'/'task' land at `in_progress` (assigned, awaiting a human
     * decision via completeTask()). 'condition'/'parallel_split'/
     * 'parallel_join' have no human action — "performing the action" IS
     * evaluating/fanning out — so this completes them itself, in the same
     * transaction, right after beginning them (§3D).
     */
    protected function beginStep(WrkflowInstance $instance, WrkflowStep $step, int $attempt = 1): WrkflowInstanceStep
    {
        if (! in_array($step->type, WrkflowStep::ENGINE_SUPPORTED_TYPES, true)) {
            throw new WorkflowEngineException(
                "Step '{$step->step_code}' is type '{$step->type}', which has no executor yet (build §3G/§3I first)."
            );
        }

        $key = WrkflowInstanceStep::idempotencyKey($instance->id, $step->id, $attempt);

        try {
            $instanceStep = WrkflowInstanceStep::query()->create([
                'instance_id' => $instance->id,
                'step_id' => $step->id,
                'status' => WrkflowInstanceStep::STATUS_PENDING,
                'attempt' => $attempt,
                'idempotency_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            // advance() (or a concurrent parallel_join arrival) re-ran and this row already
            // exists — resume from/reuse it, don't duplicate it.
            return WrkflowInstanceStep::query()->where('idempotency_key', $key)->firstOrFail();
        }

        if (in_array($step->type, WrkflowStep::HUMAN_TYPES, true)) {
            [$assignedTo, $assignedRole, $assignedTeamId] = $this->resolveAssignment($step, $instance->payload ?? []);

            $instanceStep->update([
                'status' => WrkflowInstanceStep::STATUS_IN_PROGRESS,
                'assigned_to' => $assignedTo,
                'assigned_role' => $assignedRole,
                'assigned_team_id' => $assignedTeamId,
                'started_at' => now(),
                'due_at' => $this->resolveDueAt($step, now()),
            ]);

            return $instanceStep->refresh();
        }

        $instanceStep->update(['status' => WrkflowInstanceStep::STATUS_IN_PROGRESS, 'started_at' => now()]);
        $this->finishStep($instanceStep, null, null, null);

        return $instanceStep->refresh();
    }

    /** Shared completion path for both a human decision (completeTask) and an auto-advance step (beginStep). */
    protected function finishStep(WrkflowInstanceStep $instanceStep, ?string $decision, ?string $comment, ?int $actorId): void
    {
        $instanceStep->update([
            'status' => WrkflowInstanceStep::STATUS_COMPLETED,
            'decision' => $decision,
            'comment' => $comment,
            'completed_at' => now(),
        ]);

        $this->log($instanceStep->instance, $instanceStep, WrkflowAuditLog::EVENT_STEP_COMPLETED, $actorId, $decision !== null ? ['decision' => $decision] : []);

        WorkflowStepCompleted::dispatch($instanceStep);

        $this->advance($instanceStep);
    }

    /**
     * Minimal assignee resolution the engine needs to populate a task row.
     * Full dynamic routing (role/team membership expansion, condition-driven
     * targets) is §3D's job — this only reads the step's own config.
     *
     * @return array{0: ?int, 1: ?string, 2: ?int} [assigned_to, assigned_role, assigned_team_id]
     */
    protected function resolveAssignment(WrkflowStep $step, array $payload): array
    {
        $assignee = $step->config['assignee'] ?? null;

        return match ($assignee['type'] ?? null) {
            'user' => [$assignee['user_id'] ?? null, null, null],
            'role' => [null, $assignee['role'] ?? null, null],
            'team' => [null, null, $assignee['team_id'] ?? null],
            'payload_field' => [data_get($payload, $assignee['field'] ?? '', null), null, null],
            default => [null, null, null],
        };
    }

    /** §3F: due_at = step-start + the matching wrkflow_sla_rules.sla_hours (step-level rule wins over a version-level default). No matching rule → NULL, and the step is never a candidate for the escalation sweep. */
    protected function resolveDueAt(WrkflowStep $step, Carbon $startedAt): ?Carbon
    {
        $rule = WrkflowSlaRule::resolveFor($step);

        return $rule ? $startedAt->copy()->addHours((float) $rule->sla_hours) : null;
    }

    /**
     * §3D: decide what happens next.
     * - parallel_split: fan out to EVERY outgoing transition (conditions don't
     *   apply to a split's own transitions — it's an unconditional fan-out).
     * - everything else: first transition whose condition matches wins (seq
     *   order); a NULL-condition transition is the mandatory default/else.
     */
    protected function advance(WrkflowInstanceStep $completedStep): void
    {
        $step = $completedStep->step;
        $transitions = $step->outgoingTransitions()->get();

        if ($transitions->isEmpty()) {
            $this->completeInstanceIfDone($completedStep->instance);

            return;
        }

        if ($step->type === WrkflowStep::TYPE_PARALLEL_SPLIT) {
            foreach ($transitions as $transition) {
                $this->advanceToStep($completedStep, $transition);
            }

            return;
        }

        // Evaluation context = the instance's start-snapshot payload plus this step's own
        // decision — §3D rule keeps the STORED payload an immutable start-snapshot (never a
        // live re-query of the calling module's data); the decision is transient, built here,
        // not merged back into the instance row, so it can never leak into a later step's
        // unrelated evaluation.
        $context = array_merge($completedStep->instance->payload ?? [], $completedStep->decision !== null ? ['decision' => $completedStep->decision] : []);

        $next = $this->resolveNextTransition($transitions, $context);

        if (! $next) {
            throw new WorkflowEngineException(
                "Step '{$step->step_code}' has no transition matching this instance and no default/else transition."
            );
        }

        $this->advanceToStep($completedStep, $next);
    }

    /**
     * First transition whose condition matches `$context` wins (seq order); a NULL condition
     * is the fallback default/else.
     *
     * @param  Collection<int, WrkflowTransition>  $transitions
     */
    private function resolveNextTransition(Collection $transitions, array $context): ?WrkflowTransition
    {
        $default = null;

        foreach ($transitions as $transition) {
            if ($transition->condition_expression === null) {
                $default ??= $transition;

                continue;
            }

            if ($this->evaluateCondition($transition->condition_expression, $context)) {
                return $transition;
            }
        }

        return $default;
    }

    /** @param  array{field?: string, op?: string, value?: mixed}  $condition */
    private function evaluateCondition(array $condition, array $context): bool
    {
        $actual = data_get($context, $condition['field'] ?? '');
        $expected = $condition['value'] ?? null;

        return match ($condition['op'] ?? '=') {
            '=' => $actual == $expected, // @phpstan-ignore-line — loose compare is deliberate: payload values arrive as untyped JSON
            '!=' => $actual != $expected,
            '>' => $actual > $expected,
            '<' => $actual < $expected,
            'in' => $this->conditionValueIn($actual, $expected),
            'contains' => $this->conditionContains($actual, $expected),
            default => false,
        };
    }

    /** `value` is authored as a comma-separated string from the §3B builder today; an array is also accepted for callers of the service directly. */
    private function conditionValueIn(mixed $actual, mixed $expected): bool
    {
        $list = is_array($expected) ? $expected : array_map('trim', explode(',', (string) $expected));

        return in_array((string) $actual, array_map('strval', $list), true);
    }

    private function conditionContains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array($expected, $actual, false);
        }

        return is_string($actual) && str_contains($actual, (string) $expected);
    }

    /**
     * Advances into a single target step. A parallel_join target is special: it must not
     * begin until its join rule (§3D `all`/`any`) is satisfied across the branches that feed
     * it, and must never be begun twice for the same instance — a later-arriving branch that
     * finds the join already begun (or not yet satisfied) simply stops here.
     */
    private function advanceToStep(WrkflowInstanceStep $completedStep, WrkflowTransition $transition): void
    {
        $instance = $completedStep->instance;
        $toStep = $transition->toStep;

        if ($toStep->type === WrkflowStep::TYPE_PARALLEL_JOIN) {
            $alreadyBegun = WrkflowInstanceStep::query()
                ->where('instance_id', $instance->id)->where('step_id', $toStep->id)->exists();

            if ($alreadyBegun) {
                return;
            }

            if (! $this->joinSatisfied($instance, $toStep)) {
                // Not every branch has arrived yet — legitimate as long as something is still
                // in flight to eventually satisfy it. If nothing is, the graph can never
                // converge here; fail the instance rather than leave it silently stuck at
                // `running` forever (§3C: no step is ever silently skipped or ignored).
                $this->failIfJoinCanNeverBeSatisfied($instance, $completedStep, $toStep);

                return;
            }
        }

        $nextAttempt = (WrkflowInstanceStep::query()
            ->where('instance_id', $instance->id)
            ->where('step_id', $toStep->id)
            ->max('attempt') ?? 0) + 1;

        $this->beginStep($instance, $toStep, $nextAttempt);
    }

    /**
     * §3D join rule: `all` needs every predecessor step to have completed for this instance;
     * `any` fires on the first one (later arrivals hit the `$alreadyBegun` check in
     * advanceToStep() and no-op). Predecessors are read from the graph (every step with a
     * transition into this join), not from which branches a split actually took, so this is
     * only correct for a join whose predecessors are all reachable — see
     * failIfJoinCanNeverBeSatisfied() for the case where they aren't.
     */
    private function joinSatisfied(WrkflowInstance $instance, WrkflowStep $joinStep): bool
    {
        $predecessorStepIds = WrkflowTransition::query()->where('to_step_id', $joinStep->id)->pluck('from_step_id');

        if ($predecessorStepIds->isEmpty()) {
            return true;
        }

        $completedPredecessors = WrkflowInstanceStep::query()
            ->where('instance_id', $instance->id)
            ->whereIn('step_id', $predecessorStepIds)
            ->where('status', WrkflowInstanceStep::STATUS_COMPLETED)
            ->pluck('step_id')
            ->unique()
            ->count();

        $joinRule = $joinStep->config['join_rule'] ?? 'all';

        return $joinRule === 'any' ? $completedPredecessors >= 1 : $completedPredecessors >= $predecessorStepIds->unique()->count();
    }

    private function failIfJoinCanNeverBeSatisfied(WrkflowInstance $instance, WrkflowInstanceStep $completedStep, WrkflowStep $waitingJoin): void
    {
        $hasOtherOpenSteps = $instance->steps()->whereIn('status', WrkflowInstanceStep::OPEN_STATUSES)->exists();

        if ($hasOtherOpenSteps) {
            return; // another branch is still in flight — legitimately still waiting
        }

        $this->failInstance($instance, $completedStep, [
            'reason' => "parallel_join '{$waitingJoin->step_code}' is waiting on a branch that will never complete.",
        ]);
    }

    protected function completeInstanceIfDone(WrkflowInstance $instance): void
    {
        $hasOpenSteps = $instance->steps()->whereIn('status', WrkflowInstanceStep::OPEN_STATUSES)->exists();

        if ($hasOpenSteps) {
            return;
        }

        $instance->update(['status' => WrkflowInstance::STATUS_COMPLETED, 'ended_at' => now()]);

        $this->log($instance, null, WrkflowAuditLog::EVENT_INSTANCE_COMPLETED, null);

        WorkflowInstanceCompleted::dispatch($instance);
    }

    protected function failInstance(WrkflowInstance $instance, WrkflowInstanceStep $failedStep, array $detail = []): void
    {
        $instance->update(['status' => WrkflowInstance::STATUS_FAILED, 'ended_at' => now()]);

        $this->log($instance, $failedStep, WrkflowAuditLog::EVENT_INSTANCE_FAILED, null, $detail);

        WorkflowInstanceFailed::dispatch($instance, $failedStep);
    }

    protected function log(WrkflowInstance $instance, ?WrkflowInstanceStep $instanceStep, string $eventType, ?int $actorId, array $detail = []): void
    {
        WrkflowAuditLog::query()->create([
            'instance_id' => $instance->id,
            'instance_step_id' => $instanceStep?->id,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }
}
