<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\CostCenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** §3B/§3I cost center dimension — plain CRUD, one-level hierarchy. */
class CostCenterService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): CostCenter
    {
        $this->assertParentSameCompany($data['company_id'], $data['parent_cost_center_id'] ?? null);

        return CostCenter::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(CostCenter $costCenter, array $data): CostCenter
    {
        $this->assertParentSameCompany(
            $data['company_id'] ?? $costCenter->company_id,
            $data['parent_cost_center_id'] ?? $costCenter->parent_cost_center_id,
            $costCenter->id,
        );

        return DB::transaction(function () use ($costCenter, $data) {
            $before = $costCenter->toArray();
            $costCenter->update($data);

            AuditLog::record([
                'company_id' => $costCenter->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.cost_centers',
                'subject_id' => $costCenter->id,
                'before_snapshot' => $before,
                'after_snapshot' => $costCenter->toArray(),
            ]);

            return $costCenter->refresh();
        });
    }

    public function delete(CostCenter $costCenter): void
    {
        if ($costCenter->children()->exists()) {
            throw ValidationException::withMessages(['cost_center' => 'This cost center has child cost centers — reassign or delete them first.']);
        }

        DB::transaction(function () use ($costCenter) {
            AuditLog::record([
                'company_id' => $costCenter->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.cost_centers',
                'subject_id' => $costCenter->id,
                'before_snapshot' => $costCenter->toArray(),
            ]);

            $costCenter->delete();
        });
    }

    private function assertParentSameCompany(int $companyId, ?int $parentCostCenterId, ?int $excludeId = null): void
    {
        if ($parentCostCenterId === null) {
            return;
        }

        if ($parentCostCenterId === $excludeId) {
            throw ValidationException::withMessages(['parent_cost_center_id' => 'A cost center cannot be its own parent.']);
        }

        $parent = CostCenter::query()->find($parentCostCenterId);
        if ($parent === null || $parent->company_id !== $companyId) {
            throw ValidationException::withMessages(['parent_cost_center_id' => 'The selected parent cost center is invalid.']);
        }
    }
}
