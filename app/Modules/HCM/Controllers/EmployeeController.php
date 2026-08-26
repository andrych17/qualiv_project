<?php

namespace App\Modules\HCM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Requests\StoreEmployeeRequest;
use App\Modules\HCM\Requests\UpdateEmployeeRequest;
use App\Modules\HCM\Services\EmployeeService;
use App\Modules\HCM\Services\LeaveService;
use App\Modules\HCM\Services\OrgStructureService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['employee_no', 'full_name', 'hire_date', 'employment_status'];

    public function __construct(
        protected EmployeeService $employeeService,
        protected OrgStructureService $orgStructureService,
        protected LeaveService $leaveService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'employment_status', 'position_id', 'org_unit_id', 'sort', 'direction', 'per_page');

        $query = Employee::query()
            ->with(['position.job', 'position.orgUnit', 'currentContract'])
            ->filter($filters);

        TableQuery::applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null, self::SORTABLE, 'employee_no', 'asc');

        $employees = $query->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15))
            ->withQueryString();

        return Inertia::render('HCM/Employees/Index', [
            'employees' => $employees,
            'filters' => $filters,
            'positions' => $this->orgStructureService->allPositions(),
            'orgUnits' => $this->orgStructureService->allOrgUnits(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('HCM/Employees/Create', [
            'positions' => $this->orgStructureService->allPositions(),
            'orgUnits' => $this->orgStructureService->allOrgUnits(),
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $employee = $this->employeeService->hire($request->validated(), Auth::id());

        return redirect()->route('hcm.employees.show', $employee)->with('success', 'Employee hired successfully.');
    }

    public function show(Employee $employee): Response
    {
        $employee->load([
            'position.job',
            'position.orgUnit',
            'position.reportsTo.job',
            'contracts' => fn ($q) => $q->orderByDesc('start_date'),
            'positionHistories.position.job',
            'positionHistories.changedBy',
            'attendanceLogs' => fn ($q) => $q->orderByDesc('created_at')->limit(15),
            'leaveBalances.leaveType',
            'leaveRequests.leaveType',
        ]);

        return Inertia::render('HCM/Employees/Show', [
            'employee' => $employee,
            'leaveBalances' => $this->leaveService->getEmployeeBalances($employee->id),
        ]);
    }

    public function edit(Employee $employee): Response
    {
        $employee->load(['position']);

        return Inertia::render('HCM/Employees/Edit', [
            'employee' => $employee,
            'positions' => $this->orgStructureService->allPositions(),
            'orgUnits' => $this->orgStructureService->allOrgUnits(),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->employeeService->update($employee, $request->validated(), Auth::id());

        return redirect()->route('hcm.employees.show', $employee)->with('success', 'Employee updated successfully.');
    }

    public function terminate(Request $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validate([
            'termination_date' => ['required', 'date'],
            'termination_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->employeeService->terminate($employee, $validated['termination_date'], $validated['termination_reason'] ?? null);

        return back()->with('success', 'Employee terminated successfully.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $this->employeeService->delete($employee);

        return redirect()->route('hcm.employees.index')->with('success', 'Employee deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        return $this->bulkDestroyUsing($request, Employee::class, fn (Employee $e) => $this->employeeService->delete($e));
    }
}
