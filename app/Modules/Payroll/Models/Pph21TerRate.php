<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class Pph21TerRate extends Model
{
    protected $table = 'PAYROLL.pph21_ter_rates';

    protected $fillable = [
        'ter_category',
        'min_gross_monthly',
        'max_gross_monthly',
        'rate_percentage',
        'effective_date',
        'is_active',
    ];

    protected $casts = [
        'min_gross_monthly' => 'decimal:2',
        'max_gross_monthly' => 'decimal:2',
        'rate_percentage' => 'decimal:4',
        'effective_date' => 'date',
        'is_active' => 'boolean',
    ];
}
