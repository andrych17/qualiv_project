<?php

namespace App\Modules\HCM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\AttendanceCorrection;
use App\Modules\HCM\Models\AttendanceLog;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Services\AttendanceService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    private const SORTABLE = ['created_at', 'clock_in_at', 'clock_out_at', 'exception_flag'];

    public function __construct(
        protected AttendanceService $attendanceService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'exception_flag', 'employee_id', 'date', 'sort', 'direction', 'per_page');

        $query = AttendanceLog::query()
            ->with(['employee.position.job', 'employee.position.orgUnit', 'corrections'])
            ->filter($filters);

        TableQuery::applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null, self::SORTABLE, 'created_at', 'desc');

        $logs = $query->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15))
            ->withQueryString();

        $corrections = $this->attendanceService->paginateCorrections(['status' => 'pending'], 10);

        return Inertia::render('HCM/Attendance/Index', [
            'logs' => $logs,
            'corrections' => $corrections,
            'filters' => $filters,
            'shifts' => $this->attendanceService->allShifts(),
            'employees' => Employee::query()->where('employment_status', Employee::STATUS_ACTIVE)->orderBy('full_name')->get(['id', 'employee_no', 'full_name']),
        ]);
    }

    public function clockIn(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'source' => ['nullable', 'string', 'in:web,mobile,biometric'],
        ]);

        $this->attendanceService->clockIn($validated['employee_id'], $validated['source'] ?? 'web');

        return back()->with('success', 'Clock in recorded successfully.');
    }

    public function clockOut(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
        ]);

        $this->attendanceService->clockOut($validated['employee_id']);

        return back()->with('success', 'Clock out recorded successfully.');
    }

    public function storeCorrection(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'attendance_log_id' => ['required', 'integer'],
            'requested_clock_in_at' => ['nullable', 'date'],
            'requested_clock_out_at' => ['nullable', 'date'],
            'reason' => ['required', 'string'],
        ]);

        $this->attendanceService->submitCorrection($validated['employee_id'], $validated['attendance_log_id'], $validated);

        return back()->with('success', 'Correction request submitted.');
    }

    public function reviewCorrection(Request $request, AttendanceCorrection $correction): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected'],
        ]);

        $this->attendanceService->reviewCorrection($correction, $validated['status'], Auth::id());

        return back()->with('success', 'Correction request reviewed.');
    }

    public function assignShift(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'shift_id' => ['required', 'integer'],
            'work_date' => ['required', 'date'],
        ]);

        $this->attendanceService->assignShift($validated['employee_id'], $validated['shift_id'], $validated['work_date']);

        return back()->with('success', 'Shift assigned successfully.');
    }
}
