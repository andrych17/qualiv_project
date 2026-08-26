<?php

namespace App\Modules\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\Payroll\Models\ReimbursementCategory;
use App\Modules\Payroll\Models\ReimbursementClaim;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ReimbursementController extends Controller
{
    private const SORTABLE = ['claim_date', 'amount', 'status', 'created_at'];

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'reimbursement_category_id', 'sort', 'direction', 'per_page');

        $query = ReimbursementClaim::query()
            ->with(['employee', 'category', 'reviewer']);

        if (!empty($filters['search'])) {
            $s = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($s) {
                $q->where('description', 'ilike', $s)
                    ->orWhereHas('employee', fn ($e) => $e->where('full_name', 'ilike', $s)->orWhere('employee_no', 'ilike', $s));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['reimbursement_category_id'])) {
            $query->where('reimbursement_category_id', $filters['reimbursement_category_id']);
        }

        \App\Shared\Helpers\TableQuery::applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null, self::SORTABLE, 'claim_date', 'desc');

        $claims = $query->paginate(\App\Shared\Helpers\TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15))
            ->withQueryString();

        return Inertia::render('Payroll/Reimbursements/Index', [
            'claims' => $claims,
            'categories' => ReimbursementCategory::where('is_active', true)->get(),
            'employees' => Employee::where('employment_status', Employee::STATUS_ACTIVE)->get(),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'reimbursement_category_id' => ['required', 'integer'],
            'claim_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string'],
        ]);

        ReimbursementClaim::query()->create([
            ...$validated,
            'status' => ReimbursementClaim::STATUS_PENDING,
        ]);

        return back()->with('success', 'Reimbursement claim submitted.');
    }

    public function review(Request $request, ReimbursementClaim $claim): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected'],
        ]);

        $claim->update([
            'status' => $validated['status'],
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Reimbursement claim reviewed.');
    }
}
