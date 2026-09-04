<?php

namespace Tests\Feature\HCM;

use App\Modules\HCM\Models\AttendanceCorrection;
use App\Modules\HCM\Models\AttendanceLog;
use App\Modules\HCM\Models\Shift;
use App\Modules\HCM\Models\ShiftAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpHCM;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3E — Shifts, shift assignment, clock in/out with late-exception detection, and correction submit/review. */
class AttendanceShiftTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpHCM;
    use SetsUpTenant;

    public function test_admin_can_crud_a_shift_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $this->get('/hcm/shifts')->assertOk()->assertInertia(fn ($page) => $page->component('HCM/Shifts/Index'));

        $this->post('/hcm/shifts', ['name' => 'Night Shift', 'start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 30])
            ->assertRedirect();

        $shiftId = null;
        $tenant->run(function () use (&$shiftId) {
            $shiftId = Shift::query()->where('name', 'Night Shift')->value('id');
        });

        $this->put("/hcm/shifts/{$shiftId}", ['name' => 'Night Shift (updated)', 'start_time' => '22:00', 'end_time' => '06:00'])
            ->assertRedirect();
        $tenant->run(function () use ($shiftId) {
            $this->assertSame('Night Shift (updated)', Shift::query()->find($shiftId)->name);
        });

        $this->delete("/hcm/shifts/{$shiftId}")->assertRedirect();
        $tenant->run(function () use ($shiftId) {
            $this->assertNull(Shift::query()->find($shiftId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makeShift('Bulk Shift A')->id;
            $ids[] = $this->makeShift('Bulk Shift B')->id;
        });
        $this->delete('/hcm/shifts/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, Shift::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_shift_index_filters_by_search_and_active(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $this->makeShift('Morning Shift');
            $inactive = $this->makeShift('Retired Shift');
            $inactive->update(['is_active' => false]);
        });

        $this->get('/hcm/shifts?search=Morning')->assertOk()->assertInertia(fn ($page) => $page->has('shifts.data', 1));
        $this->get('/hcm/shifts?is_active=0')->assertOk()->assertInertia(fn ($page) => $page->has('shifts.data', 1));
    }

    public function test_admin_can_assign_a_shift_and_reassigning_updates_in_place(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $shiftAId, $shiftBId] = [null, null, null];
        $tenant->run(function () use (&$employeeId, &$shiftAId, &$shiftBId) {
            $employeeId = $this->makeEmployee()->id;
            $shiftAId = $this->makeShift('Shift A', '08:00', '17:00')->id;
            $shiftBId = $this->makeShift('Shift B', '14:00', '22:00')->id;
        });

        $workDate = now()->toDateString();

        $this->post('/hcm/attendance/assign-shift', [
            'employee_id' => $employeeId,
            'shift_id' => $shiftAId,
            'work_date' => $workDate,
        ])->assertRedirect();

        // Re-assigning the same employee/date pair must update in place (unique employee_id+work_date).
        $this->post('/hcm/attendance/assign-shift', [
            'employee_id' => $employeeId,
            'shift_id' => $shiftBId,
            'work_date' => $workDate,
        ])->assertRedirect();

        $tenant->run(function () use ($employeeId, $workDate, $shiftBId) {
            $this->assertSame(1, ShiftAssignment::query()->where('employee_id', $employeeId)->where('work_date', $workDate)->count());
            $this->assertSame($shiftBId, ShiftAssignment::query()->where('employee_id', $employeeId)->value('shift_id'));
        });
    }

    public function test_clock_in_without_shift_assignment_is_on_time_and_double_clock_in_is_rejected(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $employeeId = $this->makeEmployee()->id;
        });

        $this->post('/hcm/attendance/clock-in', ['employee_id' => $employeeId, 'source' => 'mobile'])->assertRedirect();

        $tenant->run(function () use ($employeeId) {
            $log = AttendanceLog::query()->where('employee_id', $employeeId)->first();
            $this->assertSame(AttendanceLog::EXCEPTION_ON_TIME, $log->exception_flag);
            $this->assertSame('mobile', $log->source);
        });

        $this->post('/hcm/attendance/clock-in', ['employee_id' => $employeeId])->assertSessionHasErrors(['clock_in']);
    }

    public function test_clock_in_after_shift_grace_period_is_flagged_late(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(9, 30));

        $tenant = $this->loginAsHcmAdmin();

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $employee = $this->makeEmployee();
            $employeeId = $employee->id;
            $shift = $this->makeShift('Day Shift', '08:00', '17:00');
            ShiftAssignment::query()->create([
                'employee_id' => $employeeId,
                'shift_id' => $shift->id,
                'work_date' => Carbon::today()->toDateString(),
            ]);
        });

        $this->post('/hcm/attendance/clock-in', ['employee_id' => $employeeId])->assertRedirect();

        $tenant->run(function () use ($employeeId) {
            $log = AttendanceLog::query()->where('employee_id', $employeeId)->first();
            $this->assertSame(AttendanceLog::EXCEPTION_LATE, $log->exception_flag);
        });

        Carbon::setTestNow();
    }

    public function test_clock_in_within_shift_grace_period_is_on_time(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(8, 5));

        $tenant = $this->loginAsHcmAdmin();

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $employee = $this->makeEmployee();
            $employeeId = $employee->id;
            $shift = $this->makeShift('Day Shift', '08:00', '17:00');
            ShiftAssignment::query()->create([
                'employee_id' => $employeeId,
                'shift_id' => $shift->id,
                'work_date' => Carbon::today()->toDateString(),
            ]);
        });

        $this->post('/hcm/attendance/clock-in', ['employee_id' => $employeeId])->assertRedirect();

        $tenant->run(function () use ($employeeId) {
            $log = AttendanceLog::query()->where('employee_id', $employeeId)->first();
            $this->assertSame(AttendanceLog::EXCEPTION_ON_TIME, $log->exception_flag);
        });

        Carbon::setTestNow();
    }

    public function test_clock_out_requires_an_open_clock_in(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $employeeId = $this->makeEmployee()->id;
        });

        $this->post('/hcm/attendance/clock-out', ['employee_id' => $employeeId])->assertSessionHasErrors(['clock_out']);

        $this->post('/hcm/attendance/clock-in', ['employee_id' => $employeeId])->assertRedirect();
        $this->post('/hcm/attendance/clock-out', ['employee_id' => $employeeId])->assertRedirect();

        $tenant->run(function () use ($employeeId) {
            $log = AttendanceLog::query()->where('employee_id', $employeeId)->first();
            $this->assertNotNull($log->clock_out_at);
        });
    }

    public function test_admin_can_submit_and_approve_a_correction_which_updates_the_underlying_log(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $logId] = [null, null];
        $tenant->run(function () use (&$employeeId, &$logId) {
            $employeeId = $this->makeEmployee()->id;
            $logId = AttendanceLog::query()->create([
                'employee_id' => $employeeId,
                'clock_in_at' => now()->setTime(9, 30),
                'exception_flag' => AttendanceLog::EXCEPTION_LATE,
            ])->id;
        });

        $this->get('/hcm/attendance')->assertOk()->assertInertia(fn ($page) => $page->component('HCM/Attendance/Index'));

        $requestedIn = now()->setTime(8, 0)->toDateTimeString();
        $this->post('/hcm/attendance/corrections', [
            'employee_id' => $employeeId,
            'attendance_log_id' => $logId,
            'requested_clock_in_at' => $requestedIn,
            'requested_clock_out_at' => now()->setTime(17, 0)->toDateTimeString(),
            'reason' => 'Forgot badge, arrived on time.',
        ])->assertRedirect();

        $correctionId = null;
        $tenant->run(function () use (&$correctionId, $logId) {
            $correctionId = AttendanceCorrection::query()->where('attendance_log_id', $logId)->value('id');
        });

        $this->patch("/hcm/attendance/corrections/{$correctionId}/review", ['status' => AttendanceCorrection::STATUS_APPROVED])
            ->assertRedirect();

        $tenant->run(function () use ($correctionId, $logId) {
            $correction = AttendanceCorrection::query()->find($correctionId);
            $this->assertSame(AttendanceCorrection::STATUS_APPROVED, $correction->status);
            $this->assertNotNull($correction->reviewed_by);
            $this->assertNotNull($correction->reviewedBy);

            $log = AttendanceLog::query()->find($logId);
            $this->assertSame(AttendanceLog::EXCEPTION_ON_TIME, $log->exception_flag);
            $this->assertNotNull($log->clock_out_at);
        });
    }

    public function test_admin_can_reject_a_correction(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $logId, $correctionId] = [null, null, null];
        $tenant->run(function () use (&$employeeId, &$logId, &$correctionId) {
            $employeeId = $this->makeEmployee()->id;
            $logId = AttendanceLog::query()->create(['employee_id' => $employeeId, 'clock_in_at' => now()])->id;
            $correctionId = AttendanceCorrection::query()->create([
                'attendance_log_id' => $logId,
                'employee_id' => $employeeId,
                'reason' => 'Test',
                'status' => AttendanceCorrection::STATUS_PENDING,
            ])->id;
        });

        $this->patch("/hcm/attendance/corrections/{$correctionId}/review", ['status' => AttendanceCorrection::STATUS_REJECTED])
            ->assertRedirect();

        $tenant->run(function () use ($correctionId) {
            $this->assertSame(AttendanceCorrection::STATUS_REJECTED, AttendanceCorrection::query()->find($correctionId)->status);
        });
    }

    public function test_attendance_index_filters_by_search_employee_exception_and_date(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $today] = [null, now()->toDateString()];
        $tenant->run(function () use (&$employeeId) {
            $employee = $this->makeEmployee(['full_name' => 'Attendance Person']);
            $employeeId = $employee->id;
            AttendanceLog::query()->create(['employee_id' => $employeeId, 'clock_in_at' => now(), 'exception_flag' => AttendanceLog::EXCEPTION_LATE]);
        });

        $this->get('/hcm/attendance?search=Attendance Person')->assertOk()->assertInertia(fn ($page) => $page->has('logs.data', 1));
        $this->get("/hcm/attendance?employee_id={$employeeId}")->assertOk()->assertInertia(fn ($page) => $page->has('logs.data', 1));
        $this->get('/hcm/attendance?exception_flag='.AttendanceLog::EXCEPTION_LATE)->assertOk()->assertInertia(fn ($page) => $page->has('logs.data', 1));
        $this->get("/hcm/attendance?date={$today}")->assertOk()->assertInertia(fn ($page) => $page->has('logs.data', 1));
        $this->get('/hcm/attendance?sort=exception_flag&direction=asc')->assertOk();
    }

    public function test_attendance_correction_scope_filter_supports_search_and_employee_id(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $employee = $this->makeEmployee(['full_name' => 'Correction Person']);
            $log = AttendanceLog::query()->create(['employee_id' => $employee->id, 'clock_in_at' => now()]);
            AttendanceCorrection::query()->create([
                'attendance_log_id' => $log->id,
                'employee_id' => $employee->id,
                'reason' => 'Scope filter coverage',
                'status' => AttendanceCorrection::STATUS_PENDING,
            ]);

            $this->assertSame(1, AttendanceCorrection::query()->filter(['search' => 'Correction Person'])->count());
            $this->assertSame(1, AttendanceCorrection::query()->filter(['employee_id' => $employee->id])->count());
            $this->assertSame(1, AttendanceCorrection::query()->filter(['status' => AttendanceCorrection::STATUS_PENDING])->count());
        });
    }

    public function test_shift_assignment_scope_filter_supports_search_work_date_and_shift_id(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $employee = $this->makeEmployee(['full_name' => 'Assignment Person']);
            $shift = $this->makeShift();
            $workDate = now()->toDateString();
            ShiftAssignment::query()->create(['employee_id' => $employee->id, 'shift_id' => $shift->id, 'work_date' => $workDate]);

            $this->assertSame(1, ShiftAssignment::query()->filter(['search' => 'Assignment Person'])->count());
            $this->assertSame(1, ShiftAssignment::query()->filter(['work_date' => $workDate])->count());
            $this->assertSame(1, ShiftAssignment::query()->filter(['shift_id' => $shift->id])->count());
        });
    }
}
