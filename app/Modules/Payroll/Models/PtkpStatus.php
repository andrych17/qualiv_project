<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class PtkpStatus extends Model
{
    protected $table = 'PAYROLL.ptkp_statuses';

    protected $fillable = [
        'code',
        'description',
        'annual_ptkp_amount',
        'ter_category',
        'effective_date',
        'is_active',
    ];

    protected $casts = [
        'annual_ptkp_amount' => 'decimal:2',
        'effective_date' => 'date',
        'is_active' => 'boolean',
    ];
}
