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
    public function index(Request $request): Response
    {
        $query = ReimbursementClaim::query()
            ->with(['employee', 'category', 'reviewer'])
            ->latest('claim_date');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $claims = $query->paginate(15)->withQueryString();

        return Inertia::render('Payroll/Reimbursements/Index', [
            'claims' => $claims,
            'categories' => ReimbursementCategory::where('is_active', true)->get(),
            'employees' => Employee::where('employment_status', Employee::STATUS_ACTIVE)->get(),
            'filters' => $request->only(['status']),
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
