<?php

namespace App\Modules\HCM\Services;

use App\Modules\HCM\Models\AttendanceLog;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\LeaveRequest;
use Carbon\Carbon;

class HcmDashboardService
{
    public function __construct(
        protected ContractService $contractService,
    ) {}

    public function getMetrics(): array
    {
        $today = Carbon::today()->toDateString();

        $activeEmployees = Employee::query()->where('employment_status', Employee::STATUS_ACTIVE)->count();

        $onLeaveToday = LeaveRequest::query()
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->count();

        $pendingLeaveApprovals = LeaveRequest::query()
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->count();

        $todayExceptions = AttendanceLog::query()
            ->whereDate('clock_in_at', $today)
            ->where('exception_flag', '!=', AttendanceLog::EXCEPTION_ON_TIME)
            ->count();

        return [
            'active_employees' => $activeEmployees,
            'on_leave_today' => $onLeaveToday,
            'pending_leave_approvals' => $pendingLeaveApprovals,
            'today_exceptions' => $todayExceptions,
        ];
    }

    public function getDashboardQueues(): array
    {
        $today = Carbon::today()->toDateString();

        $pendingApprovals = LeaveRequest::query()
            ->with(['employee.position.job', 'leaveType'])
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->latest('created_at')
            ->limit(10)
            ->get();

        $expiringContracts = $this->contractService->getExpiringContracts(60);

        $recentHires = Employee::query()
            ->with(['position.job', 'position.orgUnit'])
            ->where('employment_status', Employee::STATUS_ACTIVE)
            ->latest('hire_date')
            ->limit(10)
            ->get();

        return [
            'pending_approvals' => $pendingApprovals,
            'expiring_contracts' => $expiringContracts,
            'recent_hires' => $recentHires,
        ];
    }
}
