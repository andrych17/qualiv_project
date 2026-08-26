<?php

namespace App\Modules\HCM\Services;

use App\Modules\HCM\Models\AttendanceCorrection;
use App\Modules\HCM\Models\AttendanceLog;
use App\Modules\HCM\Models\Shift;
use App\Modules\HCM\Models\ShiftAssignment;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    // --- Shifts ---
    public function paginateShifts(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Shift::query()
            ->filter($filters)
            ->orderBy('start_time')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function allShifts(): Collection
    {
        return Shift::query()->where('is_active', true)->orderBy('start_time')->get();
    }

    public function createShift(array $data): Shift
    {
        return Shift::create($data);
    }

    public function updateShift(Shift $shift, array $data): Shift
    {
        $shift->update($data);

        return $shift;
    }

    public function deleteShift(Shift $shift): void
    {
        $shift->delete();
    }

    // --- Shift Assignments ---
    public function assignShift(int $employeeId, int $shiftId, string $workDate): ShiftAssignment
    {
        return ShiftAssignment::updateOrCreate(
            ['employee_id' => $employeeId, 'work_date' => $workDate],
            ['shift_id' => $shiftId]
        );
    }

    // --- Attendance Logs ---
    public function paginateLogs(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return AttendanceLog::query()
            ->with(['employee.position.job', 'employee.position.orgUnit'])
            ->filter($filters)
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function clockIn(int $employeeId, ?string $source = 'web'): AttendanceLog
    {
        $today = Carbon::today()->toDateString();
        $existing = AttendanceLog::query()
            ->where('employee_id', $employeeId)
            ->whereDate('clock_in_at', $today)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages(['clock_in' => 'Employee already clocked in today.']);
        }

        $now = Carbon::now();
        $exception = $this->determineExceptionFlag($employeeId, $today, $now);

        return AttendanceLog::create([
            'employee_id' => $employeeId,
            'clock_in_at' => $now,
            'source' => $source ?? 'web',
            'exception_flag' => $exception,
        ]);
    }

    public function clockOut(int $employeeId): AttendanceLog
    {
        $today = Carbon::today()->toDateString();
        $log = AttendanceLog::query()
            ->where('employee_id', $employeeId)
            ->whereDate('clock_in_at', $today)
            ->whereNull('clock_out_at')
            ->latest('id')
            ->first();

        if (! $log) {
            throw ValidationException::withMessages(['clock_out' => 'No active clock-in found for today.']);
        }

        $log->update([
            'clock_out_at' => Carbon::now(),
        ]);

        return $log;
    }

    // --- Corrections ---
    public function paginateCorrections(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return AttendanceCorrection::query()
            ->with(['employee.position.job', 'attendanceLog', 'reviewedBy'])
            ->filter($filters)
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function submitCorrection(int $employeeId, int $attendanceLogId, array $data): AttendanceCorrection
    {
        return AttendanceCorrection::create([
            'employee_id' => $employeeId,
            'attendance_log_id' => $attendanceLogId,
            'requested_clock_in_at' => $data['requested_clock_in_at'] ?? null,
            'requested_clock_out_at' => $data['requested_clock_out_at'] ?? null,
            'reason' => $data['reason'] ?? null,
            'status' => AttendanceCorrection::STATUS_PENDING,
        ]);
    }

    public function reviewCorrection(AttendanceCorrection $correction, string $status, int $reviewerUserId): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $status, $reviewerUserId) {
            $correction->update([
                'status' => $status,
                'reviewed_by' => $reviewerUserId,
                'reviewed_at' => Carbon::now(),
            ]);

            if ($status === AttendanceCorrection::STATUS_APPROVED) {
                $log = $correction->attendanceLog;
                if ($log) {
                    $updates = [];
                    if ($correction->requested_clock_in_at) {
                        $updates['clock_in_at'] = $correction->requested_clock_in_at;
                    }
                    if ($correction->requested_clock_out_at) {
                        $updates['clock_out_at'] = $correction->requested_clock_out_at;
                    }
                    $updates['exception_flag'] = AttendanceLog::EXCEPTION_ON_TIME;
                    $log->update($updates);
                }
            }

            return $correction;
        });
    }

    protected function determineExceptionFlag(int $employeeId, string $date, Carbon $clockInTime): string
    {
        $assignment = ShiftAssignment::query()
            ->with('shift')
            ->where('employee_id', $employeeId)
            ->where('work_date', $date)
            ->first();

        if (! $assignment || ! $assignment->shift) {
            return AttendanceLog::EXCEPTION_ON_TIME;
        }

        $shiftStart = Carbon::parse($date.' '.$assignment->shift->start_time);

        // If clock in is later than shift start + 15 min grace period
        if ($clockInTime->greaterThan($shiftStart->copy()->addMinutes(15))) {
            return AttendanceLog::EXCEPTION_LATE;
        }

        return AttendanceLog::EXCEPTION_ON_TIME;
    }
}
