<?php

namespace Tests\Feature;

use App\Modules\HCM\Models\AttendanceLog;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\EmployeePositionHistory;
use App\Modules\HCM\Models\EmploymentContract;
use App\Modules\HCM\Models\Job;
use App\Modules\HCM\Models\LeaveBalance;
use App\Modules\HCM\Models\LeaveRequest;
use App\Modules\HCM\Models\LeaveType;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\HCM\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class HcmModuleTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_starter_plan_blocks_hcm_module(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'starter']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/hcm/dashboard')->assertForbidden();
    }

    public function test_admin_can_access_hcm_dashboard_and_hire_employee(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        // Dashboard renders
        $this->get('/hcm/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('HCM/Dashboard/Index'));

        // Setup Org Unit & Position
        $orgUnitId = null;
        $positionId = null;
        $tenant->run(function () use (&$orgUnitId, &$positionId) {
            $ou = OrgUnit::query()->create(['name' => 'Finance Ops', 'is_active' => true]);
            $job = Job::query()->create(['code' => 'ACC_LEAD', 'title' => 'Accounting Lead', 'is_active' => true]);
            $pos = Position::query()->create(['job_id' => $job->id, 'org_unit_id' => $ou->id, 'is_active' => true]);
            $orgUnitId = $ou->id;
            $positionId = $pos->id;
        });

        // New Hire Onboarding (Employee + Contract + Position History in 1 step)
        $this->post('/hcm/employees', [
            'full_name' => 'Budi Santoso',
            'hire_date' => '2026-03-01',
            'nik' => '3201234567890001',
            'npwp' => '01.234.567.8-901.000',
            'position_id' => $positionId,
            'contract_type' => 'PKWTT',
            'base_salary' => 12500000,
            'probation_end_date' => '2026-06-01',
            'bank_name' => 'BCA',
            'bank_account_no' => '1234567890',
        ])->assertRedirect();

        $employeeId = null;
        $tenant->run(function () use (&$employeeId, $positionId) {
            $emp = Employee::query()->where('full_name', 'Budi Santoso')->first();
            $this->assertNotNull($emp);
            $this->assertStringStartsWith('EMP-', $emp->employee_no);
            $this->assertEquals(Employee::STATUS_ACTIVE, $emp->employment_status);
            $this->assertEquals($positionId, $emp->position_id);

            // Verify Contract created
            $contract = EmploymentContract::query()->where('employee_id', $emp->id)->first();
            $this->assertNotNull($contract);
            $this->assertEquals('PKWTT', $contract->contract_type);
            $this->assertEquals(12500000, $contract->base_salary);

            // Verify Position History logged
            $history = EmployeePositionHistory::query()->where('employee_id', $emp->id)->first();
            $this->assertNotNull($history);
            $this->assertEquals($positionId, $history->position_id);

            $employeeId = $emp->id;
        });

        // Show employee details
        $this->get("/hcm/employees/{$employeeId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('HCM/Employees/Show'));

        // Update employee
        $this->put("/hcm/employees/{$employeeId}", [
            'employee_no' => 'EMP-0099',
            'full_name' => 'Budi Santoso S.E.',
            'hire_date' => '2026-03-01',
            'employment_status' => 'active',
            'position_id' => $positionId,
        ])->assertRedirect();

        // Terminate employee
        $this->post("/hcm/employees/{$employeeId}/terminate", [
            'termination_date' => '2026-08-01',
            'termination_reason' => 'Resignation',
        ])->assertRedirect();

        $tenant->run(function () use ($employeeId) {
            $emp = Employee::query()->find($employeeId);
            $this->assertEquals(Employee::STATUS_TERMINATED, $emp->employment_status);
            $this->assertEquals('2026-08-01', $emp->termination_date->toDateString());
            $this->assertEquals('Resignation', $emp->termination_reason);

            $contract = EmploymentContract::query()->where('employee_id', $employeeId)->first();
            $this->assertEquals(EmploymentContract::STATUS_TERMINATED, $contract->status);
        });
    }

    public function test_attendance_clock_in_out_workflow(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $emp = Employee::query()->create([
                'employee_no' => 'EMP-1001',
                'full_name' => 'Ahmad Dahlan',
                'hire_date' => '2026-01-01',
                'employment_status' => 'active',
            ]);
            $employeeId = $emp->id;
        });

        // Clock In
        $this->post('/hcm/attendance/clock-in', [
            'employee_id' => $employeeId,
            'source' => 'web',
        ])->assertRedirect();

        $tenant->run(function () use ($employeeId) {
            $log = AttendanceLog::query()->where('employee_id', $employeeId)->first();
            $this->assertNotNull($log);
            $this->assertNotNull($log->clock_in_at);
            $this->assertNull($log->clock_out_at);
        });

        // Clock Out
        $this->post('/hcm/attendance/clock-out', [
            'employee_id' => $employeeId,
        ])->assertRedirect();

        $tenant->run(function () use ($employeeId) {
            $log = AttendanceLog::query()->where('employee_id', $employeeId)->first();
            $this->assertNotNull($log->clock_out_at);
        });
    }

    public function test_leave_request_and_balance_deduction(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $employeeId = null;
        $leaveTypeId = null;

        $tenant->run(function () use (&$employeeId, &$leaveTypeId) {
            $emp = Employee::query()->create([
                'employee_no' => 'EMP-2001',
                'full_name' => 'Dewi Sartika',
                'hire_date' => '2025-01-01',
                'employment_status' => 'active',
            ]);

            $type = LeaveType::query()->create([
                'code' => 'ANNUAL',
                'name' => 'Cuti Tahunan',
                'is_paid' => true,
                'is_active' => true,
            ]);

            LeaveBalance::query()->create([
                'employee_id' => $emp->id,
                'leave_type_id' => $type->id,
                'period_year' => 2026,
                'entitled_days' => 12,
                'used_days' => 0,
                'carried_over_days' => 0,
            ]);

            $employeeId = $emp->id;
            $leaveTypeId = $type->id;
        });

        // Submit 3-day leave request
        $this->post('/hcm/leave/requests', [
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-03',
            'reason' => 'Family vacation',
        ])->assertRedirect();

        $requestId = null;
        $tenant->run(function () use (&$requestId, $employeeId) {
            $req = LeaveRequest::query()->where('employee_id', $employeeId)->first();
            $this->assertNotNull($req);
            $this->assertEquals(LeaveRequest::STATUS_PENDING, $req->status);
            $this->assertEquals(3, $req->days_count);
            $requestId = $req->id;
        });

        // Approve leave request -> balance used_days should increase by 3
        $this->patch("/hcm/leave/requests/{$requestId}/review", [
            'status' => 'approved',
        ])->assertRedirect();

        $tenant->run(function () use ($employeeId, $leaveTypeId) {
            $balance = LeaveBalance::query()
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveTypeId)
                ->where('period_year', 2026)
                ->first();

            $this->assertEquals(3, $balance->used_days);
            $this->assertEquals(9, $balance->remaining_days);
        });

        // Cancel approved leave -> refunds balance back to 0 used
        $this->post("/hcm/leave/requests/{$requestId}/cancel")->assertRedirect();

        $tenant->run(function () use ($employeeId, $leaveTypeId) {
            $balance = LeaveBalance::query()
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveTypeId)
                ->where('period_year', 2026)
                ->first();

            $this->assertEquals(0, $balance->used_days);
            $this->assertEquals(12, $balance->remaining_days);
        });
    }

    public function test_contract_renewal_and_pkwt_compliance_rules(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $employeeId = null;
        $contractId = null;

        $tenant->run(function () use (&$employeeId, &$contractId) {
            $emp = Employee::query()->create([
                'employee_no' => 'EMP-3001',
                'full_name' => 'Farhan Kamil',
                'hire_date' => '2025-01-01',
                'employment_status' => 'active',
            ]);

            $c = EmploymentContract::query()->create([
                'employee_id' => $emp->id,
                'contract_type' => 'PKWT',
                'start_date' => '2025-01-01',
                'end_date' => '2026-01-01',
                'base_salary' => 8000000,
                'status' => 'active',
            ]);

            $employeeId = $emp->id;
            $contractId = $c->id;
        });

        // Renew contract (extend PKWT 1 year)
        $this->post("/hcm/contracts/{$contractId}/renew", [
            'contract_type' => 'PKWT',
            'start_date' => '2026-01-01',
            'end_date' => '2027-01-01',
            'base_salary' => 9000000,
        ])->assertRedirect();

        $tenant->run(function () use ($contractId, $employeeId) {
            $old = EmploymentContract::query()->find($contractId);
            $this->assertEquals(EmploymentContract::STATUS_RENEWED, $old->status);

            $new = EmploymentContract::query()
                ->where('employee_id', $employeeId)
                ->where('status', EmploymentContract::STATUS_ACTIVE)
                ->first();

            $this->assertNotNull($new);
            $this->assertEquals($contractId, $new->renewed_from_contract_id);
            $this->assertEquals(9000000, $new->base_salary);
        });
    }

    public function test_org_units_and_positions_crud(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        // Create Org Unit
        $this->post('/hcm/org-units', [
            'name' => 'Human Resources',
            'is_active' => true,
        ])->assertRedirect();

        $orgUnitId = null;
        $jobId = null;

        $tenant->run(function () use (&$orgUnitId, &$jobId) {
            $ou = OrgUnit::query()->where('name', 'Human Resources')->first();
            $this->assertNotNull($ou);
            $orgUnitId = $ou->id;

            $job = Job::query()->create(['code' => 'HR_MGR', 'title' => 'HR Manager', 'is_active' => true]);
            $jobId = $job->id;
        });

        // Create Position
        $this->post('/hcm/positions', [
            'job_id' => $jobId,
            'org_unit_id' => $orgUnitId,
            'headcount_cap' => 2,
            'is_active' => true,
        ])->assertRedirect();

        $tenant->run(function () use ($jobId, $orgUnitId) {
            $pos = Position::query()->where('job_id', $jobId)->where('org_unit_id', $orgUnitId)->first();
            $this->assertNotNull($pos);
            $this->assertEquals(2, $pos->headcount_cap);
        });
    }
}
