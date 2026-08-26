<?php

namespace App\Modules\Payroll\Models;

use App\Modules\HCM\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayrollProfile extends Model
{
    protected $table = 'PAYROLL.employee_payroll_profiles';

    protected $fillable = [
        'employee_id',
        'payroll_group_id',
        'salary_structure_id',
        'ptkp_status_code',
        'npwp_number',
        'has_npwp',
        'bpjs_kesehatan_no',
        'bpjs_ketenagakerjaan_no',
        'jkk_risk_category_id',
        'is_tax_borne_by_company',
        'proration_rule',
        'is_active',
    ];

    protected $casts = [
        'has_npwp' => 'boolean',
        'is_tax_borne_by_company' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function payrollGroup(): BelongsTo
    {
        return $this->belongsTo(PayrollGroup::class, 'payroll_group_id');
    }

    public function salaryStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function jkkRiskCategory(): BelongsTo
    {
        return $this->belongsTo(JkkRiskCategory::class, 'jkk_risk_category_id');
    }
}
