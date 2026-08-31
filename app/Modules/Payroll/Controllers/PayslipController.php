<?php

namespace App\Modules\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Models\PayrollRunLine;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PAYROLL_SPECS.md's own "Future Version" note names HCM's (unbuilt) ESS portal as the
 * eventual employee-facing front door for payslip viewing, reading through a
 * `PayrollService::getPayslips()` that was never built either. Until that exists, this index
 * is the HR/payroll-admin browse of every payslip line across every calculated run — same
 * audience as Runs/Profiles/Components/Structures (this whole module is `menu.perm:PAYROLL`
 * gated), not an employee self-service view. Rows only exist once a run has been calculated
 * (PayrollRunService::calculateRun creates them), so there's nothing to filter on run status.
 */
class PayslipController extends Controller
{
    private const SORTABLE = ['gross_total', 'net_total', 'take_home_pay'];

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'payroll_run_id', 'sort', 'direction', 'per_page');

        $query = PayrollRunLine::query()
            ->with(['employee:id,employee_no,full_name', 'payrollRun:id,run_number,period_start,period_end,pay_date']);

        if (! empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $query->whereHas('employee', fn ($q) => $q->where('full_name', 'ilike', $s)->orWhere('employee_no', 'ilike', $s));
        }

        if (! empty($filters['payroll_run_id'])) {
            $query->where('payroll_run_id', $filters['payroll_run_id']);
        }

        TableQuery::applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc');

        $payslips = $query->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString();

        return Inertia::render('Payroll/Payslips/Index', [
            'payslips' => $payslips,
            'filters' => $filters,
        ]);
    }

    public function show(PayrollRunLine $line): Response
    {
        $line->load([
            'payrollRun.payrollGroup',
            'employee.position.job',
            'employee.position.orgUnit',
            'employee.currentContract',
            'details',
        ]);

        return Inertia::render('Payroll/Payslips/Show', [
            'payslip' => $line,
        ]);
    }
}
