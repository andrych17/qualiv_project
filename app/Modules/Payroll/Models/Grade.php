<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $table = 'PAYROLL.grades';

    protected $fillable = [
        'code',
        'name',
        'min_salary',
        'max_salary',
        'is_active',
    ];

    protected $casts = [
        'min_salary' => 'decimal:2',
        'max_salary' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
