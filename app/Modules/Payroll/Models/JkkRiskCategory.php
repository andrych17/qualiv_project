<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class JkkRiskCategory extends Model
{
    protected $table = 'PAYROLL.jkk_risk_categories';

    protected $fillable = [
        'code',
        'name',
        'employer_rate',
        'is_active',
    ];

    protected $casts = [
        'employer_rate' => 'decimal:4',
        'is_active' => 'boolean',
    ];
}
