<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\EmploymentContract;
use App\Modules\HCM\Models\Job;
use App\Modules\HCM\Models\LeavePolicy;
use App\Modules\HCM\Models\LeaveType;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\HCM\Models\Position;
use App\Modules\HCM\Models\Shift;

/** Shared bootstrap for HCM module tests — plan activation, admin login, and master-data fixtures. */
trait SetsUpHCM
{
    protected function loginAsHcmAdmin(): Tenant
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        return $tenant;
    }

    protected function makeOrgUnit(string $name = 'Engineering', string $unitType = OrgUnit::TYPE_DEPARTMENT, ?int $parentOrgUnitId = null): OrgUnit
    {
        return OrgUnit::query()->create([
            'name' => $name,
            'unit_type' => $unitType,
            'parent_org_unit_id' => $parentOrgUnitId,
            'is_active' => true,
        ]);
    }

    protected function makeJob(string $code = 'ENG-1', string $title = 'Engineer'): Job
    {
        return Job::query()->firstOrCreate(['code' => $code], ['title' => $title, 'is_active' => true]);
    }

    protected function makePosition(?Job $job = null, ?OrgUnit $orgUnit = null, array $attrs = []): Position
    {
        return Position::query()->create([
            'job_id' => $attrs['job_id'] ?? ($job ?? $this->makeJob())->id,
            'org_unit_id' => $attrs['org_unit_id'] ?? ($orgUnit ?? $this->makeOrgUnit())->id,
            'reports_to_position_id' => $attrs['reports_to_position_id'] ?? null,
            'headcount_cap' => $attrs['headcount_cap'] ?? null,
            'is_active' => true,
        ]);
    }

    protected function makeShift(string $name = 'Day Shift', string $start = '08:00', string $end = '17:00'): Shift
    {
        return Shift::query()->create([
            'name' => $name,
            'start_time' => $start,
            'end_time' => $end,
            'break_minutes' => 60,
            'is_active' => true,
        ]);
    }

    protected function makeLeaveType(string $code = 'ANNUAL', string $name = 'Annual Leave'): LeaveType
    {
        return LeaveType::query()->firstOrCreate(['code' => $code], [
            'name' => $name,
            'is_paid' => true,
            'requires_attachment' => false,
            'is_active' => true,
        ]);
    }

    protected function makeLeavePolicy(LeaveType $leaveType, array $attrs = []): LeavePolicy
    {
        return LeavePolicy::query()->create([
            'leave_type_id' => $leaveType->id,
            'contract_type' => $attrs['contract_type'] ?? null,
            'entitlement_days_per_year' => $attrs['entitlement_days_per_year'] ?? 12,
            'accrual_method' => $attrs['accrual_method'] ?? 'annual_grant',
            'carry_over_max_days' => $attrs['carry_over_max_days'] ?? 0,
            'carry_over_expiry_months' => $attrs['carry_over_expiry_months'] ?? null,
            'is_paid' => $attrs['is_paid'] ?? true,
        ]);
    }

    protected function makeEmployee(array $attrs = []): Employee
    {
        static $seq = 0;
        $seq++;

        return Employee::query()->create([
            'employee_no' => $attrs['employee_no'] ?? sprintf('EMP-%04d', 9000 + $seq),
            'full_name' => $attrs['full_name'] ?? "Employee {$seq}",
            'hire_date' => $attrs['hire_date'] ?? now()->subYear()->toDateString(),
            'employment_status' => $attrs['employment_status'] ?? Employee::STATUS_ACTIVE,
            'position_id' => $attrs['position_id'] ?? null,
            'marital_status' => $attrs['marital_status'] ?? 'single',
            'dependents_count' => $attrs['dependents_count'] ?? 0,
            ...$attrs,
        ]);
    }

    protected function makeContract(Employee $employee, array $attrs = []): EmploymentContract
    {
        return EmploymentContract::query()->create([
            'employee_id' => $employee->id,
            'contract_type' => $attrs['contract_type'] ?? EmploymentContract::TYPE_PKWTT,
            'start_date' => $attrs['start_date'] ?? $employee->hire_date,
            'end_date' => $attrs['end_date'] ?? null,
            'base_salary' => $attrs['base_salary'] ?? 5000000,
            'work_location' => $attrs['work_location'] ?? 'HQ',
            'probation_end_date' => $attrs['probation_end_date'] ?? null,
            'status' => $attrs['status'] ?? EmploymentContract::STATUS_ACTIVE,
            'renewed_from_contract_id' => $attrs['renewed_from_contract_id'] ?? null,
        ]);
    }
}
