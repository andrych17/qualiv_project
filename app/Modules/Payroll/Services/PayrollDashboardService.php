<?php

namespace App\Modules\Payroll\Services;

use App\Modules\HCM\Models\Employee;
use App\Modules\Payroll\Models\EmployeePayrollProfile;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\ReimbursementClaim;

class PayrollDashboardService
{
    public function getMetrics(): array
    {
        $lastCompletedRun = PayrollRun::query()
            ->whereIn('status', [PayrollRun::STATUS_APPROVED, PayrollRun::STATUS_PAID, PayrollRun::STATUS_LOCKED])
            ->latest('pay_date')
            ->first();

        $activeEmployeesCount = Employee::query()
            ->where('employment_status', Employee::STATUS_ACTIVE)
            ->count();

        $pendingReimbursements = ReimbursementClaim::query()
            ->where('status', ReimbursementClaim::STATUS_PENDING)
            ->count();

        return [
            'last_run_total_net' => (float) ($lastCompletedRun?->total_net ?? 0),
            'last_run_tax_pph21' => (float) ($lastCompletedRun?->total_tax_pph21 ?? 0),
            'last_run_bpjs_total' => (float) (($lastCompletedRun?->total_bpjs_employer ?? 0) + ($lastCompletedRun?->total_bpjs_employee ?? 0)),
            'active_employees' => $activeEmployeesCount,
            'pending_reimbursements' => $pendingReimbursements,
        ];
    }

    public function getQueues(): array
    {
        $recentRuns = PayrollRun::query()
            ->with('payrollGroup')
            ->latest('created_at')
            ->limit(5)
            ->get();

        $pendingReimbursements = ReimbursementClaim::query()
            ->with(['employee', 'category'])
            ->where('status', ReimbursementClaim::STATUS_PENDING)
            ->latest('created_at')
            ->limit(5)
            ->get();

        return [
            'recent_runs' => $recentRuns,
            'pending_reimbursements' => $pendingReimbursements,
        ];
    }
}
