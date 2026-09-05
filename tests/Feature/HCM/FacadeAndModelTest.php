<?php

namespace Tests\Feature\HCM;

use App\Modules\HCM\Models\EmployeePositionHistory;
use App\Modules\HCM\Models\EmploymentContract;
use App\Modules\HCM\Models\LeaveBalance;
use App\Modules\HCM\Models\LeaveRequest;
use App\Modules\HCM\Models\LeaveType;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\HCM\Models\RegionalMinimumWage;
use App\Modules\HCM\Models\ShiftAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpHCM;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Model relation and scope coverage for paths with no dedicated controller/route: RegionalMinimumWage lookup table and the org-chart/contract-chain self-referencing relations. */
class FacadeAndModelTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpHCM;
    use SetsUpTenant;

    public function test_regional_minimum_wage_scope_filter(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            RegionalMinimumWage::query()->create([
                'region_code' => 'DKI-JKT',
                'region_name' => 'DKI Jakarta',
                'effective_date' => now()->startOfYear()->toDateString(),
                'monthly_wage_amount' => 5067381,
            ]);
            RegionalMinimumWage::query()->create([
                'region_code' => 'JABAR',
                'region_name' => 'West Java',
                'effective_date' => now()->startOfYear()->toDateString(),
                'monthly_wage_amount' => 2057495,
            ]);

            $this->assertSame(1, RegionalMinimumWage::query()->filter(['search' => 'Jakarta'])->count());
            $this->assertSame(1, RegionalMinimumWage::query()->filter(['search' => 'JABAR'])->count());
            $this->assertSame(2, RegionalMinimumWage::query()->filter([])->count());
        });
    }

    public function test_org_chart_self_referencing_relations(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $parent = $this->makeOrgUnit('Parent Division');
            $child = $this->makeOrgUnit('Child Department', OrgUnit::TYPE_DEPARTMENT, $parent->id);

            $this->assertTrue($parent->children->contains('id', $child->id));
            $this->assertSame($parent->id, $child->parent->id);

            $job = $this->makeJob();
            $this->assertTrue($job->positions->isEmpty());

            $orgUnit = $this->makeOrgUnit();
            $manager = $this->makePosition($job, $orgUnit);
            $report = $this->makePosition($job, $orgUnit, ['reports_to_position_id' => $manager->id]);

            $this->assertTrue($manager->directReports->contains('id', $report->id));
            $this->assertSame($manager->id, $report->reportsTo->id);
            $this->assertTrue($orgUnit->positions->contains('id', $report->id));

            $employee = $this->makeEmployee(['position_id' => $report->id]);
            $this->assertTrue($report->employees->contains('id', $employee->id));
        });
    }

    public function test_employee_current_contract_picks_latest_active_and_payroll_profile_relation_is_empty(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $employee = $this->makeEmployee();
            $this->makeContract($employee, ['start_date' => now()->subYears(2)->toDateString()]);
            $latest = $this->makeContract($employee, ['start_date' => now()->subMonths(1)->toDateString()]);

            $employee->refresh();
            $this->assertSame($latest->id, $employee->currentContract->id);
            $this->assertNull($employee->payrollProfile);
        });
    }

    public function test_contract_renewal_chain_relations(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $employee = $this->makeEmployee();
            $original = $this->makeContract($employee, [
                'contract_type' => EmploymentContract::TYPE_PKWT,
                'start_date' => now()->subYear()->toDateString(),
                'end_date' => now()->subDays(10)->toDateString(),
                'status' => EmploymentContract::STATUS_RENEWED,
            ]);
            $renewal = $this->makeContract($employee, [
                'contract_type' => EmploymentContract::TYPE_PKWT,
                'start_date' => now()->subDays(9)->toDateString(),
                'end_date' => now()->addMonths(6)->toDateString(),
                'renewed_from_contract_id' => $original->id,
            ]);

            $this->assertSame($original->id, $renewal->renewedFrom->id);
            $this->assertTrue($original->renewals->contains('id', $renewal->id));
        });
    }

    public function test_leave_type_relations_and_shift_assignments_relation(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $leaveType = $this->makeLeaveType();
            $this->makeLeavePolicy($leaveType);
            $this->assertCount(1, $leaveType->policies);

            $employee = $this->makeEmployee();
            LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'status' => LeaveRequest::STATUS_PENDING,
            ]);
            $this->assertCount(1, $leaveType->fresh()->requests);

            $shift = $this->makeShift();
            ShiftAssignment::query()->create([
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'work_date' => now()->toDateString(),
            ]);
            $this->assertCount(1, $shift->fresh()->assignments);
        });
    }

    public function test_leave_type_balances_relation_and_scope_filter(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $employee = $this->makeEmployee();
            $leaveType = $this->makeLeaveType('BALANCE-TEST', 'Balance Test Leave');
            $this->makeLeavePolicy($leaveType);
            $balance = LeaveBalance::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'period_year' => (int) date('Y'),
                'entitled_days' => 12,
            ]);
            $this->assertCount(1, $leaveType->fresh()->balances);

            $inactive = LeaveType::query()->create(['code' => 'GONE', 'name' => 'Gone Type', 'is_active' => false]);

            $this->assertSame(1, LeaveType::query()->filter(['search' => 'Balance Test'])->count());
            $this->assertSame(1, LeaveType::query()->filter(['is_active' => false])->count());
            $this->assertSame($inactive->id, LeaveType::query()->filter(['is_active' => false])->first()->id);

            $this->assertSame($employee->id, $balance->employee->id);
            $this->assertSame($leaveType->id, $balance->leaveType->id);
        });
    }

    public function test_leave_policy_leave_type_relation_and_employee_position_history_employee_relation(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $leaveType = $this->makeLeaveType();
            $policy = $this->makeLeavePolicy($leaveType);
            $this->assertSame($leaveType->id, $policy->leaveType->id);

            $employee = $this->makeEmployee();
            $history = EmployeePositionHistory::query()->create([
                'employee_id' => $employee->id,
                'position_id' => $this->makePosition()->id,
                'effective_from' => now()->toDateString(),
            ]);
            $this->assertSame($employee->id, $history->employee->id);
        });
    }

    public function test_employee_shift_assignments_relation(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $employee = $this->makeEmployee();
            $shift = $this->makeShift();
            ShiftAssignment::query()->create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'work_date' => now()->toDateString()]);

            $this->assertCount(1, $employee->fresh()->shiftAssignments);
        });
    }
}
