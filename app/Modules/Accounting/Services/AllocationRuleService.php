<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AllocationRule;
use App\Modules\Accounting\Models\AllocationRuleTarget;
use App\Modules\Accounting\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3I — allocation rule CRUD. AllocationRunService is what actually posts a journal from
 * one of these. Percentages are validated to sum to exactly 100 at save time (not run
 * time) — same "reject the whole shape upfront, not just when it's used" discipline
 * §3P's RecurringJournalTemplateService applies to an unbalanced template.
 *
 * v1 consequence of requiring exactly 100% and rejecting the source cost center as a
 * target: a rule can only ever redistribute its ENTIRE source pool, never leave a portion
 * behind in overhead. That's a deliberate v1 limitation (not an oversight) — loosening it
 * would mean allowing < 100%, which nothing here currently supports.
 */
class AllocationRuleService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{cost_center_id:int, percentage:float}>  $targets
     */
    public function create(array $data, array $targets, int $userId): AllocationRule
    {
        $this->assertTargetsValid($data['source_cost_center_id'] ?? null, $targets);

        return DB::transaction(function () use ($data, $targets, $userId) {
            $rule = AllocationRule::query()->create([
                ...$data,
                'uuid' => (string) Str::uuid(),
                'created_by' => $userId,
            ]);

            $this->replaceTargets($rule, $targets);

            AuditLog::record([
                'company_id' => $rule->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.allocation_rules',
                'subject_id' => $rule->id,
                'actor_id' => $userId,
                'after_snapshot' => $rule->toArray(),
            ]);

            return $rule->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{cost_center_id:int, percentage:float}>  $targets
     */
    public function update(AllocationRule $rule, array $data, array $targets, int $userId): AllocationRule
    {
        $sourceCostCenterId = $data['source_cost_center_id'] ?? $rule->source_cost_center_id;
        $this->assertTargetsValid($sourceCostCenterId, $targets);

        return DB::transaction(function () use ($rule, $data, $targets, $userId) {
            $before = $rule->toArray();
            $rule->update($data);
            $this->replaceTargets($rule, $targets);

            AuditLog::record([
                'company_id' => $rule->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.allocation_rules',
                'subject_id' => $rule->id,
                'actor_id' => $userId,
                'before_snapshot' => $before,
                'after_snapshot' => $rule->toArray(),
            ]);

            return $rule->refresh();
        });
    }

    public function delete(AllocationRule $rule, int $userId): void
    {
        if ($rule->runs()->exists()) {
            throw ValidationException::withMessages(['rule' => 'This rule has already been run for at least one period and cannot be deleted — deactivate it instead.']);
        }

        DB::transaction(function () use ($rule, $userId) {
            AuditLog::record([
                'company_id' => $rule->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.allocation_rules',
                'subject_id' => $rule->id,
                'actor_id' => $userId,
                'before_snapshot' => $rule->toArray(),
            ]);

            $rule->delete();
        });
    }

    public function setActive(AllocationRule $rule, bool $active, int $userId): AllocationRule
    {
        return DB::transaction(function () use ($rule, $active, $userId) {
            $before = $rule->toArray();
            $rule->update(['is_active' => $active]);

            AuditLog::record([
                'company_id' => $rule->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.allocation_rules',
                'subject_id' => $rule->id,
                'actor_id' => $userId,
                'before_snapshot' => $before,
                'after_snapshot' => $rule->toArray(),
            ]);

            return $rule->refresh();
        });
    }

    /** @param  list<array{cost_center_id:int, percentage:float}>  $targets */
    private function assertTargetsValid(?int $sourceCostCenterId, array $targets): void
    {
        if (empty($targets)) {
            throw ValidationException::withMessages(['targets' => 'A rule needs at least one target cost center.']);
        }

        $costCenterIds = array_column($targets, 'cost_center_id');
        if (count($costCenterIds) !== count(array_unique($costCenterIds))) {
            throw ValidationException::withMessages(['targets' => 'Each target cost center can only appear once.']);
        }

        // A target equal to the source would debit and credit the same cost center on the
        // same account — that portion silently nets to zero and the allocation quietly
        // under-delivers with no error anywhere else, so it's rejected here instead.
        if ($sourceCostCenterId !== null && in_array($sourceCostCenterId, $costCenterIds, true)) {
            throw ValidationException::withMessages(['targets' => 'A target cost center cannot be the same as the source cost center.']);
        }

        $total = round(array_sum(array_column($targets, 'percentage')), 2);
        if (abs($total - 100.0) > 0.005) {
            throw ValidationException::withMessages(['targets' => "Target percentages must sum to exactly 100 — currently {$total}."]);
        }
    }

    /** @param  list<array{cost_center_id:int, percentage:float}>  $targets */
    private function replaceTargets(AllocationRule $rule, array $targets): void
    {
        $rule->targets()->delete();

        foreach ($targets as $target) {
            AllocationRuleTarget::query()->create([
                'allocation_rule_id' => $rule->id,
                'cost_center_id' => $target['cost_center_id'],
                'percentage' => $target['percentage'],
            ]);
        }
    }
}
