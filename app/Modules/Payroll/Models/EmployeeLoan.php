<?php

namespace App\Modules\Payroll\Models;

use App\Modules\HCM\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLoan extends Model
{
    protected $table = 'PAYROLL.employee_loans';

    protected $fillable = [
        'employee_id',
        'loan_type_id',
        'principal_amount',
        'monthly_installment',
        'remaining_balance',
        'tenure_months',
        'status',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'monthly_installment' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
        'tenure_months' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function loanType(): BelongsTo
    {
        return $this->belongsTo(LoanType::class, 'loan_type_id');
    }
}
