<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\PayrollComponentGlMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3S — CRUD for the component_code → GL account mapping. Unlike §3H's mapping (item OR
 * category, an either/or scope), a component_code here is always a single flat key — the
 * DB's own unique(company_id, component_code) constraint is sufficient, no service-level
 * upsert-by-scope trickery needed.
 */
class PayrollComponentGlMappingService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data, int $userId): PayrollComponentGlMapping
    {
        $this->assertPayableRequiredForEmployerCost($data);

        return DB::transaction(function () use ($data, $userId) {
            $mapping = PayrollComponentGlMapping::query()->create([
                ...$data,
                'uuid' => (string) Str::uuid(),
                'created_by' => $userId,
            ]);

            AuditLog::record([
                'company_id' => $mapping->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.payroll_component_gl_mappings',
                'subject_id' => $mapping->id,
                'actor_id' => $userId,
                'after_snapshot' => $mapping->toArray(),
            ]);

            return $mapping->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(PayrollComponentGlMapping $mapping, array $data, int $userId): PayrollComponentGlMapping
    {
        $merged = [...$mapping->only(['component_type', 'payable_account_id']), ...$data];
        $this->assertPayableRequiredForEmployerCost($merged);

        return DB::transaction(function () use ($mapping, $data, $userId) {
            $before = $mapping->toArray();
            $mapping->update($data);

            AuditLog::record([
                'company_id' => $mapping->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.payroll_component_gl_mappings',
                'subject_id' => $mapping->id,
                'actor_id' => $userId,
                'before_snapshot' => $before,
                'after_snapshot' => $mapping->toArray(),
            ]);

            return $mapping->refresh();
        });
    }

    public function delete(PayrollComponentGlMapping $mapping, int $userId): void
    {
        DB::transaction(function () use ($mapping, $userId) {
            AuditLog::record([
                'company_id' => $mapping->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.payroll_component_gl_mappings',
                'subject_id' => $mapping->id,
                'actor_id' => $userId,
                'before_snapshot' => $mapping->toArray(),
            ]);

            $mapping->delete();
        });
    }

    /** @param  array<string, mixed>  $data */
    private function assertPayableRequiredForEmployerCost(array $data): void
    {
        if (($data['component_type'] ?? null) === PayrollComponentGlMapping::TYPE_EMPLOYER_COST && empty($data['payable_account_id'])) {
            throw ValidationException::withMessages(['payable_account_id' => 'An employer-cost component needs a payable account too — it both debits an expense and credits a payable.']);
        }
    }
}
