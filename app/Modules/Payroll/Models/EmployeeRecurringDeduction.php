<?php

namespace App\Modules\Payroll\Models;

use App\Modules\HCM\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRecurringDeduction extends Model
{
    protected $table = 'PAYROLL.employee_recurring_deductions';

    protected $fillable = [
        'employee_id',
        'payroll_component_id',
        'amount',
        'start_period',
        'end_period',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function payrollComponent(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }
}
