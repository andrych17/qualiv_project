<?php

namespace App\Modules\HCM\Services;

use App\Modules\HCM\Models\LeaveBalance;
use App\Modules\HCM\Models\LeavePolicy;
use App\Modules\HCM\Models\LeaveRequest;
use App\Modules\HCM\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    // --- Leave Types & Policies ---
    public function allTypes(): Collection
    {
        return LeaveType::query()->with('policies')->where('is_active', true)->orderBy('name')->get();
    }

    public function createType(array $data): LeaveType
    {
        return LeaveType::create($data);
    }

    public function updateType(LeaveType $type, array $data): LeaveType
    {
        $type->update($data);

        return $type;
    }

    public function setPolicy(LeaveType $type, array $data): LeavePolicy
    {
        return LeavePolicy::updateOrCreate(
            ['leave_type_id' => $type->id, 'contract_type' => $data['contract_type'] ?? null],
            [
                'entitlement_days_per_year' => $data['entitlement_days_per_year'],
                'accrual_method' => $data['accrual_method'] ?? 'annual_grant',
                'carry_over_max_days' => $data['carry_over_max_days'] ?? 0,
                'carry_over_expiry_months' => $data['carry_over_expiry_months'] ?? null,
                'is_paid' => $data['is_paid'] ?? true,
            ]
        );
    }

    // --- Leave Balances ---
    public function getBalance(int $employeeId, int $leaveTypeId, ?int $year = null): LeaveBalance
    {
        $year = $year ?? (int) date('Y');

        return LeaveBalance::firstOrCreate(
            ['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'period_year' => $year],
            ['entitled_days' => 12, 'used_days' => 0, 'carried_over_days' => 0]
        );
    }

    public function getEmployeeBalances(int $employeeId, ?int $year = null): Collection
    {
        $year = $year ?? (int) date('Y');

        return LeaveBalance::query()
            ->with('leaveType')
            ->where('employee_id', $employeeId)
            ->where('period_year', $year)
            ->get();
    }

    // --- Leave Requests ---
    public function paginateRequests(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return LeaveRequest::query()
            ->with(['employee.position.job', 'employee.position.orgUnit', 'leaveType', 'reviewedBy'])
            ->filter($filters)
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function submitRequest(int $employeeId, array $data): LeaveRequest
    {
        $startDate = Carbon::parse($data['start_date']);
        $endDate = Carbon::parse($data['end_date']);

        if ($endDate->lt($startDate)) {
            throw ValidationException::withMessages(['end_date' => 'End date cannot be earlier than start date.']);
        }

        $daysRequested = $startDate->diffInDays($endDate) + 1;
        $leaveType = LeaveType::findOrFail($data['leave_type_id']);

        // Check balance if leave type is paid/entitled
        $year = (int) $startDate->format('Y');
        $balance = $this->getBalance($employeeId, $leaveType->id, $year);

        if ($balance->remaining_days < $daysRequested && $leaveType->code === 'ANNUAL') {
            // Soft warning or validate balance
            if ($balance->remaining_days <= 0) {
                throw ValidationException::withMessages(['leave_type_id' => 'Insufficient leave balance. Remaining days: '.$balance->remaining_days]);
            }
        }

        return LeaveRequest::create([
            'employee_id' => $employeeId,
            'leave_type_id' => $data['leave_type_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'] ?? null,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
    }

    public function reviewRequest(LeaveRequest $request, string $status, int $reviewerUserId): LeaveRequest
    {
        return DB::transaction(function () use ($request, $status, $reviewerUserId) {
            $request->update([
                'status' => $status,
                'reviewed_by' => $reviewerUserId,
                'reviewed_at' => Carbon::now(),
            ]);

            if ($status === LeaveRequest::STATUS_APPROVED) {
                // Deduct from leave balance
                $year = (int) Carbon::parse($request->start_date)->format('Y');
                $balance = $this->getBalance($request->employee_id, $request->leave_type_id, $year);
                $days = $request->days_count;

                $balance->increment('used_days', $days);
            }

            return $request;
        });
    }

    public function cancelRequest(LeaveRequest $request): LeaveRequest
    {
        return DB::transaction(function () use ($request) {
            if ($request->status === LeaveRequest::STATUS_APPROVED) {
                // Refund deducted balance
                $year = (int) Carbon::parse($request->start_date)->format('Y');
                $balance = $this->getBalance($request->employee_id, $request->leave_type_id, $year);
                $balance->decrement('used_days', $request->days_count);
            }

            $request->update(['status' => LeaveRequest::STATUS_CANCELLED]);

            return $request;
        });
    }
}
