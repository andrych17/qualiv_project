<?php

namespace App\Modules\HCM\Services;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\EmployeePositionHistory;
use App\Modules\HCM\Models\EmploymentContract;
use App\Modules\SysConfig\Services\ConfigService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EmployeeService
{
    public function __construct(
        protected ConfigService $configService,
    ) {}

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Employee::query()
            ->with(['position.job', 'position.orgUnit', 'currentContract'])
            ->filter($filters)
            ->orderBy('employee_no')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Minimal Hire Entry Point (§3D / §3B):
     * Creates Employee + initial Position assignment history + initial Contract in one transaction.
     */
    public function hire(array $data, ?int $changedByUserId = null): Employee
    {
        return DB::transaction(function () use ($data, $changedByUserId) {
            if (empty($data['employee_no'])) {
                $data['employee_no'] = $this->generateEmployeeNo();
            }

            $employee = Employee::create([
                'employee_no' => $data['employee_no'],
                'full_name' => $data['full_name'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'gender' => $data['gender'] ?? null,
                'nik' => $data['nik'] ?? null,
                'npwp' => $data['npwp'] ?? null,
                'bpjs_kesehatan_no' => $data['bpjs_kesehatan_no'] ?? null,
                'bpjs_ketenagakerjaan_no' => $data['bpjs_ketenagakerjaan_no'] ?? null,
                'address' => $data['address'] ?? null,
                'marital_status' => $data['marital_status'] ?? 'single',
                'dependents_count' => $data['dependents_count'] ?? 0,
                'religion' => $data['religion'] ?? null,
                'hire_date' => $data['hire_date'],
                'employment_status' => Employee::STATUS_ACTIVE,
                'position_id' => $data['position_id'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account_no' => $data['bank_account_no'] ?? null,
                'bank_account_holder_name' => $data['bank_account_holder_name'] ?? $data['full_name'],
                'linked_partner_id' => $data['linked_partner_id'] ?? null,
            ]);

            if (! empty($data['position_id'])) {
                EmployeePositionHistory::create([
                    'employee_id' => $employee->id,
                    'position_id' => $data['position_id'],
                    'effective_from' => $data['hire_date'],
                    'changed_by' => $changedByUserId,
                ]);
            }

            if (! empty($data['contract_type'])) {
                EmploymentContract::create([
                    'employee_id' => $employee->id,
                    'contract_type' => $data['contract_type'],
                    'start_date' => $data['hire_date'],
                    'end_date' => $data['contract_type'] === EmploymentContract::TYPE_PKWT ? ($data['contract_end_date'] ?? null) : null,
                    'base_salary' => $data['base_salary'] ?? 0,
                    'work_location' => $data['work_location'] ?? 'HQ',
                    'probation_end_date' => $data['contract_type'] === EmploymentContract::TYPE_PKWTT ? ($data['probation_end_date'] ?? null) : null,
                    'status' => EmploymentContract::STATUS_ACTIVE,
                ]);
            }

            return $employee->load(['position.job', 'position.orgUnit', 'currentContract']);
        });
    }

    public function update(Employee $employee, array $data, ?int $changedByUserId = null): Employee
    {
        return DB::transaction(function () use ($employee, $data, $changedByUserId) {
            $oldPositionId = $employee->position_id;
            $newPositionId = $data['position_id'] ?? null;

            if ($newPositionId && $newPositionId != $oldPositionId) {
                EmployeePositionHistory::query()
                    ->where('employee_id', $employee->id)
                    ->whereNull('effective_to')
                    ->update(['effective_to' => now()->toDateString()]);

                EmployeePositionHistory::create([
                    'employee_id' => $employee->id,
                    'position_id' => $newPositionId,
                    'effective_from' => now()->toDateString(),
                    'changed_by' => $changedByUserId,
                ]);
            }

            $employee->update($data);

            return $employee->load(['position.job', 'position.orgUnit', 'currentContract']);
        });
    }

    public function terminate(Employee $employee, string $terminationDate, ?string $reason = null): Employee
    {
        return DB::transaction(function () use ($employee, $terminationDate, $reason) {
            $employee->update([
                'employment_status' => Employee::STATUS_TERMINATED,
                'termination_date' => $terminationDate,
                'termination_reason' => $reason,
            ]);

            EmploymentContract::query()
                ->where('employee_id', $employee->id)
                ->where('status', EmploymentContract::STATUS_ACTIVE)
                ->update(['status' => EmploymentContract::STATUS_TERMINATED]);

            return $employee;
        });
    }

    public function delete(Employee $employee): void
    {
        $employee->delete();
    }

    protected function generateEmployeeNo(): string
    {
        $next = Employee::query()->max('id') + 1;

        return sprintf('EMP-%04d', $next);
    }
}
