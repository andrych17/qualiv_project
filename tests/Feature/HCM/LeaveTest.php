<?php

namespace Tests\Feature\HCM;

use App\Modules\HCM\Models\LeaveBalance;
use App\Modules\HCM\Models\LeavePolicy;
use App\Modules\HCM\Models\LeaveRequest;
use App\Modules\HCM\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpHCM;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3F — Leave Management: type/policy setup, first-request balance auto-provisioning (regression for the missing-import bug in LeaveService::getBalance), submit/review/cancel with balance deduction and refund. */
class LeaveTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpHCM;
    use SetsUpTenant;

    public function test_admin_can_create_a_leave_type_and_reject_duplicate_code(): void
    {
        $this->loginAsHcmAdmin();

        $this->post('/hcm/leave/types', ['code' => 'SICK', 'name' => 'Sick Leave'])->assertRedirect();
        $this->post('/hcm/leave/types', ['code' => 'SICK', 'name' => 'Sick Leave Again'])->assertSessionHasErrors(['code']);
    }

    public function test_admin_can_set_a_leave_policy(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $leaveTypeId = null;
        $tenant->run(function () use (&$leaveTypeId) {
            $leaveTypeId = $this->makeLeaveType()->id;
        });

        $this->post("/hcm/leave/types/{$leaveTypeId}/policy", [
            'contract_type' => 'PKWTT',
            'entitlement_days_per_year' => 14,
            'accrual_method' => 'annual_grant',
            'carry_over_max_days' => 3,
        ])->assertRedirect();

        $tenant->run(function () use ($leaveTypeId) {
            $policy = LeavePolicy::query()->where('leave_type_id', $leaveTypeId)->first();
            $this->assertEqualsWithDelta(14.0, (float) $policy->entitlement_days_per_year, 0.001);
        });

        // Re-setting the same (leave_type_id, contract_type) pair updates in place instead of duplicating.
        $this->post("/hcm/leave/types/{$leaveTypeId}/policy", [
            'contract_type' => 'PKWTT',
            'entitlement_days_per_year' => 18,
            'accrual_method' => 'annual_grant',
        ])->assertRedirect();

        $tenant->run(function () use ($leaveTypeId) {
            $this->assertSame(1, LeavePolicy::query()->where('leave_type_id', $leaveTypeId)->count());
        });
    }

    public function test_first_ever_leave_request_auto_provisions_a_balance_from_the_policy(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $leaveTypeId] = [null, null];
        $tenant->run(function () use (&$employeeId, &$leaveTypeId) {
            $employeeId = $this->makeEmployee()->id;
            $leaveType = $this->makeLeaveType('ANNUAL', 'Annual Leave');
            $leaveTypeId = $leaveType->id;
            $this->makeLeavePolicy($leaveType, ['entitlement_days_per_year' => 12]);
        });

        $this->post('/hcm/leave/requests', [
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'reason' => 'Vacation',
        ])->assertRedirect();

        $tenant->run(function () use ($employeeId, $leaveTypeId) {
            $balance = LeaveBalance::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->first();
            $this->assertNotNull($balance);
            $this->assertEqualsWithDelta(12.0, (float) $balance->entitled_days, 0.001);
            $this->assertSame(1, LeaveRequest::query()->where('employee_id', $employeeId)->count());
        });
    }

    public function test_annual_leave_request_exceeding_balance_is_rejected_but_other_types_are_not_balance_checked(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $annualId, $unpaidId] = [null, null, null];
        $tenant->run(function () use (&$employeeId, &$annualId, &$unpaidId) {
            $employeeId = $this->makeEmployee()->id;
            $annual = $this->makeLeaveType('ANNUAL', 'Annual Leave');
            $this->makeLeavePolicy($annual, ['entitlement_days_per_year' => 2]);
            $annualId = $annual->id;
            $unpaidId = $this->makeLeaveType('UNPAID', 'Unpaid Leave')->id;
        });

        // Requesting 5 days against a 2-day ANNUAL entitlement must be rejected.
        $this->post('/hcm/leave/requests', [
            'employee_id' => $employeeId,
            'leave_type_id' => $annualId,
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ])->assertSessionHasErrors(['leave_type_id']);

        // UNPAID has no balance-sufficiency check regardless of days requested.
        $this->post('/hcm/leave/requests', [
            'employee_id' => $employeeId,
            'leave_type_id' => $unpaidId,
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $leaveTypeId] = [null, null];
        $tenant->run(function () use (&$employeeId, &$leaveTypeId) {
            $employeeId = $this->makeEmployee()->id;
            $leaveTypeId = $this->makeLeaveType()->id;
        });

        $this->post('/hcm/leave/requests', [
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
        ])->assertSessionHasErrors(['end_date']);
    }

    public function test_store_rejects_invalid_employee_and_leave_type(): void
    {
        $this->loginAsHcmAdmin();

        $this->post('/hcm/leave/requests', [
            'employee_id' => 999999,
            'leave_type_id' => 999999,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ])->assertSessionHasErrors(['employee_id', 'leave_type_id']);
    }

    public function test_approving_a_leave_request_deducts_balance_and_cancelling_it_refunds(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $leaveTypeId, $requestId] = [null, null, null];
        $tenant->run(function () use (&$employeeId, &$leaveTypeId, &$requestId) {
            $employeeId = $this->makeEmployee()->id;
            $leaveType = $this->makeLeaveType('ANNUAL', 'Annual Leave');
            $this->makeLeavePolicy($leaveType, ['entitlement_days_per_year' => 10]);
            $leaveTypeId = $leaveType->id;

            $requestId = LeaveRequest::query()->create([
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'start_date' => now()->addDays(1)->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'status' => LeaveRequest::STATUS_PENDING,
            ])->id;
        });

        $this->get('/hcm/leave')->assertOk()->assertInertia(fn ($page) => $page->component('HCM/Leave/Index'));

        $this->patch("/hcm/leave/requests/{$requestId}/review", ['status' => LeaveRequest::STATUS_APPROVED])->assertRedirect();

        $tenant->run(function () use ($employeeId, $leaveTypeId, $requestId) {
            $balance = LeaveBalance::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->first();
            $this->assertEqualsWithDelta(2.0, (float) $balance->used_days, 0.001);
            $this->assertSame(LeaveRequest::STATUS_APPROVED, LeaveRequest::query()->find($requestId)->status);
        });

        $this->post("/hcm/leave/requests/{$requestId}/cancel")->assertRedirect();

        $tenant->run(function () use ($employeeId, $leaveTypeId, $requestId) {
            $balance = LeaveBalance::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->first();
            $this->assertEqualsWithDelta(0.0, (float) $balance->used_days, 0.001);
            $this->assertSame(LeaveRequest::STATUS_CANCELLED, LeaveRequest::query()->find($requestId)->status);
        });
    }

    public function test_reviewing_a_non_pending_request_and_double_cancelling_are_rejected(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $requestId = null;
        $tenant->run(function () use (&$requestId) {
            $employee = $this->makeEmployee();
            $leaveType = $this->makeLeaveType();
            $requestId = LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'status' => LeaveRequest::STATUS_APPROVED,
            ])->id;
        });

        $this->patch("/hcm/leave/requests/{$requestId}/review", ['status' => LeaveRequest::STATUS_REJECTED])
            ->assertSessionHasErrors(['status']);

        $this->post("/hcm/leave/requests/{$requestId}/cancel")->assertRedirect();
        $this->post("/hcm/leave/requests/{$requestId}/cancel")->assertSessionHasErrors(['status']);
    }

    public function test_leave_index_filters_by_search_status_employee_and_leave_type(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $leaveTypeId] = [null, null];
        $tenant->run(function () use (&$employeeId, &$leaveTypeId) {
            $employee = $this->makeEmployee(['full_name' => 'Leave Requester']);
            $employeeId = $employee->id;
            $leaveType = $this->makeLeaveType();
            $leaveTypeId = $leaveType->id;
            LeaveRequest::query()->create([
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'status' => LeaveRequest::STATUS_PENDING,
            ]);
        });

        $this->get('/hcm/leave?search=Leave Requester')->assertOk()->assertInertia(fn ($page) => $page->has('requests.data', 1));
        $this->get('/hcm/leave?status='.LeaveRequest::STATUS_PENDING)->assertOk()->assertInertia(fn ($page) => $page->has('requests.data', 1));
        $this->get("/hcm/leave?employee_id={$employeeId}")->assertOk()->assertInertia(fn ($page) => $page->has('requests.data', 1));
        $this->get("/hcm/leave?leave_type_id={$leaveTypeId}")->assertOk()->assertInertia(fn ($page) => $page->has('requests.data', 1));
        $this->get('/hcm/leave?sort=status&direction=asc')->assertOk();
    }

    public function test_leave_request_days_count_accessor_handles_missing_dates(): void
    {
        $request = new LeaveRequest;
        $this->assertSame(0, $request->days_count);
    }

    public function test_leave_balance_remaining_days_accessor_and_scope_filter(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $employee = $this->makeEmployee();
            $leaveType = $this->makeLeaveType();
            $balance = LeaveBalance::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'period_year' => (int) date('Y'),
                'entitled_days' => 12,
                'used_days' => 3,
                'carried_over_days' => 1,
            ]);

            $this->assertEqualsWithDelta(10.0, $balance->remaining_days, 0.001);
            $this->assertSame(1, LeaveBalance::query()->filter(['employee_id' => $employee->id])->count());
            $this->assertSame(1, LeaveBalance::query()->filter(['period_year' => (int) date('Y')])->count());
            $this->assertSame(1, LeaveBalance::query()->filter(['leave_type_id' => $leaveType->id])->count());
        });
    }

    public function test_leave_type_all_types_lists_active_types_with_policies(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $leaveType = $this->makeLeaveType('MATERNITY', 'Maternity Leave');
            $this->makeLeavePolicy($leaveType);
            LeaveType::query()->create(['code' => 'INACTIVE', 'name' => 'Inactive Type', 'is_active' => false]);
        });

        $this->get('/hcm/leave')->assertOk()
            ->assertInertia(fn ($page) => $page->has('leaveTypes', 1));
    }
}
