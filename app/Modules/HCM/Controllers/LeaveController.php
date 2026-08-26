<?php

namespace App\Modules\HCM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\LeaveRequest;
use App\Modules\HCM\Models\LeaveType;
use App\Modules\HCM\Requests\StoreLeaveRequestRequest;
use App\Modules\HCM\Services\LeaveService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LeaveController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['start_date', 'end_date', 'status', 'created_at'];

    public function __construct(
        protected LeaveService $leaveService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'employee_id', 'leave_type_id', 'sort', 'direction', 'per_page');

        $query = LeaveRequest::query()
            ->with(['employee.position.job', 'employee.position.orgUnit', 'leaveType', 'reviewedBy'])
            ->filter($filters);

        TableQuery::applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null, self::SORTABLE, 'created_at', 'desc');

        $requests = $query->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15))
            ->withQueryString();

        return Inertia::render('HCM/Leave/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'leaveTypes' => $this->leaveService->allTypes(),
            'employees' => Employee::query()->where('employment_status', Employee::STATUS_ACTIVE)->orderBy('full_name')->get(['id', 'employee_no', 'full_name']),
        ]);
    }

    public function store(StoreLeaveRequestRequest $request): RedirectResponse
    {
        $this->leaveService->submitRequest($request->input('employee_id'), $request->validated());

        return back()->with('success', 'Leave request submitted.');
    }

    public function review(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected'],
        ]);

        $this->leaveService->reviewRequest($leaveRequest, $validated['status'], Auth::id());

        return back()->with('success', 'Leave request reviewed.');
    }

    public function cancel(LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->leaveService->cancelRequest($leaveRequest);

        return back()->with('success', 'Leave request cancelled.');
    }

    public function storeType(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:100'],
            'is_paid' => ['nullable', 'boolean'],
            'requires_attachment' => ['nullable', 'boolean'],
        ]);

        if (LeaveType::query()->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => 'The leave type code has already been taken.']);
        }

        $this->leaveService->createType($validated);

        return back()->with('success', 'Leave type created.');
    }

    public function setPolicy(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $validated = $request->validate([
            'contract_type' => ['nullable', 'string', 'in:PKWT,PKWTT'],
            'entitlement_days_per_year' => ['required', 'numeric', 'min:0'],
            'accrual_method' => ['required', 'string', 'in:annual_grant,monthly_accrual'],
            'carry_over_max_days' => ['nullable', 'numeric', 'min:0'],
            'carry_over_expiry_months' => ['nullable', 'integer', 'min:1'],
            'is_paid' => ['nullable', 'boolean'],
        ]);

        $this->leaveService->setPolicy($leaveType, $validated);

        return back()->with('success', 'Leave policy saved.');
    }
}
