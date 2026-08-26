<?php

namespace App\Modules\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Models\PayrollGroup;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollRunService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PayrollRunController extends Controller
{
    private const SORTABLE = ['run_number', 'period_start', 'period_end', 'pay_date', 'total_gross', 'total_net', 'status', 'run_type'];

    public function __construct(
        protected PayrollRunService $payrollRunService
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'run_type', 'sort', 'direction', 'per_page');

        $query = PayrollRun::query()
            ->with('payrollGroup');

        if (!empty($filters['search'])) {
            $s = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($s) {
                $q->where('run_number', 'ilike', $s)
                    ->orWhereHas('payrollGroup', fn ($g) => $g->where('name', 'ilike', $s));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['run_type'])) {
            $query->where('run_type', $filters['run_type']);
        }

        \App\Shared\Helpers\TableQuery::applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null, self::SORTABLE, 'period_end', 'desc');

        $runs = $query->paginate(\App\Shared\Helpers\TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15))
            ->withQueryString();

        return Inertia::render('Payroll/Runs/Index', [
            'runs' => $runs,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $groups = PayrollGroup::query()->where('is_active', true)->get();

        return Inertia::render('Payroll/Runs/Create', [
            'payrollGroups' => $groups,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'run_number' => ['nullable', 'string', 'max:50'],
            'payroll_group_id' => ['nullable', 'integer'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'pay_date' => ['nullable', 'date'],
            'run_type' => ['required', 'string', 'in:regular,off_cycle,thr,bonus,severance'],
        ]);

        $run = $this->payrollRunService->createDraftRun($validated);

        return redirect()->route('payroll.runs.show', $run->id)->with('success', 'Draft payroll run created.');
    }

    public function show(PayrollRun $run): Response
    {
        $run->load(['payrollGroup', 'lines.employee.position.job', 'lines.employee.position.orgUnit', 'approvedByUser', 'lockedByUser']);

        return Inertia::render('Payroll/Runs/Show', [
            'run' => $run,
        ]);
    }

    public function calculate(PayrollRun $run): RedirectResponse
    {
        $this->payrollRunService->calculateRun($run);

        return back()->with('success', 'Payroll calculation completed.');
    }

    public function approve(PayrollRun $run): RedirectResponse
    {
        $this->payrollRunService->approveRun($run, Auth::id());

        return back()->with('success', 'Payroll run approved.');
    }

    public function markPaid(PayrollRun $run): RedirectResponse
    {
        $this->payrollRunService->markPaid($run);

        return back()->with('success', 'Payroll marked as paid.');
    }

    public function lock(PayrollRun $run): RedirectResponse
    {
        $this->payrollRunService->lockRun($run, Auth::id());

        return back()->with('success', 'Payroll run locked.');
    }
}
