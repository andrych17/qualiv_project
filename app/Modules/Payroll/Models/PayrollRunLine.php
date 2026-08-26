<?php

namespace App\Modules\Payroll\Models;

use App\Modules\HCM\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRunLine extends Model
{
    protected $table = 'PAYROLL.payroll_run_lines';

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'basic_salary',
        'taxable_earnings',
        'non_taxable_earnings',
        'gross_total',
        'bpjs_kesehatan_employer',
        'bpjs_kesehatan_employee',
        'bpjs_tk_employer',
        'bpjs_tk_employee',
        'pph21_amount',
        'other_deductions',
        'net_total',
        'take_home_pay',
        'ptkp_status_code',
        'ter_category',
        'ter_rate_percentage',
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'taxable_earnings' => 'decimal:2',
        'non_taxable_earnings' => 'decimal:2',
        'gross_total' => 'decimal:2',
        'bpjs_kesehatan_employer' => 'decimal:2',
        'bpjs_kesehatan_employee' => 'decimal:2',
        'bpjs_tk_employer' => 'decimal:2',
        'bpjs_tk_employee' => 'decimal:2',
        'pph21_amount' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'net_total' => 'decimal:2',
        'take_home_pay' => 'decimal:2',
        'ter_rate_percentage' => 'decimal:4',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PayrollRunLineDetail::class, 'payroll_run_line_id');
    }
}
