<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class LoanType extends Model
{
    protected $table = 'PAYROLL.loan_types';

    protected $fillable = [
        'code',
        'name',
        'interest_method',
        'max_loan_limit',
        'is_active',
    ];

    protected $casts = [
        'max_loan_limit' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
