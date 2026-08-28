<?php

namespace App\Modules\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HCM\Models\Employee;
use App\Modules\Payroll\Models\EmployeePayrollProfile;
use App\Modules\Payroll\Models\JkkRiskCategory;
use App\Modules\Payroll\Models\PayrollGroup;
use App\Modules\Payroll\Models\PtkpStatus;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeePayrollProfileController extends Controller
{
    private const SORTABLE = ['employee_no', 'full_name', 'hire_date'];

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $query = Employee::query()
            ->with(['payrollProfile.payrollGroup', 'payrollProfile.salaryStructure', 'currentContract', 'position.job', 'position.orgUnit'])
            ->where('employment_status', Employee::STATUS_ACTIVE);

        if (! empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($s) {
                $q->where('full_name', 'ilike', $s)
                    ->orWhere('employee_no', 'ilike', $s);
            });
        }

        TableQuery::applySort($query, $filters['sort'] ?? null, $filters['direction'] ?? null, self::SORTABLE, 'employee_no', 'asc');

        $employees = $query->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 15))
            ->withQueryString();

        return Inertia::render('Payroll/Profiles/Index', [
            'employees' => $employees,
            'payrollGroups' => PayrollGroup::all(),
            'salaryStructures' => SalaryStructure::all(),
            'ptkpStatuses' => PtkpStatus::all(),
            'jkkCategories' => JkkRiskCategory::all(),
            'filters' => $filters,
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validate([
            'payroll_group_id' => ['nullable', 'integer'],
            'salary_structure_id' => ['nullable', 'integer'],
            'ptkp_status_code' => ['required', 'string', 'max:20'],
            'npwp_number' => ['nullable', 'string', 'max:30'],
            'has_npwp' => ['nullable', 'boolean'],
            'bpjs_kesehatan_no' => ['nullable', 'string', 'max:30'],
            'bpjs_ketenagakerjaan_no' => ['nullable', 'string', 'max:30'],
            'jkk_risk_category_id' => ['nullable', 'integer'],
            'is_tax_borne_by_company' => ['nullable', 'boolean'],
            'proration_rule' => ['nullable', 'string', 'in:work_days,calendar_days,none'],
        ]);

        EmployeePayrollProfile::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            $validated
        );

        return back()->with('success', 'Employee payroll profile updated.');
    }
}
