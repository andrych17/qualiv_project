<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class SeveranceRuleMatrix extends Model
{
    protected $table = 'PAYROLL.severance_rule_matrices';

    protected $fillable = [
        'term_reason',
        'years_of_service_min',
        'years_of_service_max',
        'severance_months',
        'reward_months',
        'compensation_rate',
        'effective_date',
        'is_active',
    ];

    protected $casts = [
        'years_of_service_min' => 'integer',
        'years_of_service_max' => 'integer',
        'severance_months' => 'decimal:2',
        'reward_months' => 'decimal:2',
        'compensation_rate' => 'decimal:2',
        'effective_date' => 'date',
        'is_active' => 'boolean',
    ];
}
