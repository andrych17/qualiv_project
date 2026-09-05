<?php

namespace Tests\Feature\HCM;

use App\Modules\HCM\Models\AttendanceLog;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\EmploymentContract;
use App\Modules\HCM\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpHCM;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** HCM Dashboard: active/on-leave/pending-approval/exception metrics and the pending-approvals/expiring-contracts/recent-hires queues. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpHCM;
    use SetsUpTenant;

    public function test_dashboard_reports_metrics_and_queues(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $active = $this->makeEmployee(['full_name' => 'Recent Hire', 'hire_date' => now()->toDateString()]);
            $this->makeEmployee(['employment_status' => Employee::STATUS_SUSPENDED]);

            $leaveType = $this->makeLeaveType();

            // On-leave-today: an approved request spanning today.
            LeaveRequest::query()->create([
                'employee_id' => $active->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => now()->subDay()->toDateString(),
                'end_date' => now()->addDay()->toDateString(),
                'status' => LeaveRequest::STATUS_APPROVED,
            ]);

            // Pending approval queue.
            LeaveRequest::query()->create([
                'employee_id' => $active->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => now()->addDays(10)->toDateString(),
                'end_date' => now()->addDays(11)->toDateString(),
                'status' => LeaveRequest::STATUS_PENDING,
            ]);

            // Today exception.
            AttendanceLog::query()->create([
                'employee_id' => $active->id,
                'clock_in_at' => now(),
                'exception_flag' => AttendanceLog::EXCEPTION_LATE,
            ]);

            // Expiring contract within 60 days.
            $this->makeContract($active, [
                'contract_type' => EmploymentContract::TYPE_PKWT,
                'start_date' => now()->subMonths(3)->toDateString(),
                'end_date' => now()->addDays(20)->toDateString(),
            ]);
        });

        $this->get('/hcm/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->component('HCM/Dashboard/Index')
                ->where('metrics.active_employees', 1)
                ->where('metrics.on_leave_today', 1)
                ->where('metrics.pending_leave_approvals', 1)
                ->where('metrics.today_exceptions', 1)
                ->has('queues.pending_approvals', 1)
                ->has('queues.expiring_contracts', 1)
                ->has('queues.recent_hires', 1)
            );
    }

    public function test_dashboard_metrics_are_zero_with_no_data(): void
    {
        $this->loginAsHcmAdmin();

        $this->get('/hcm/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('metrics.active_employees', 0)
                ->where('metrics.on_leave_today', 0)
                ->where('metrics.pending_leave_approvals', 0)
                ->where('metrics.today_exceptions', 0)
            );
    }
}
