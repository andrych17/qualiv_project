<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class DeductionRuleConfig extends Model
{
    protected $table = 'PAYROLL.deduction_rule_configs';

    protected $fillable = [
        'code',
        'name',
        'deduction_type',
        'insufficient_funds_behavior',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
