<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollGroup extends Model
{
    protected $table = 'PAYROLL.payroll_groups';

    protected $fillable = [
        'code',
        'name',
        'payroll_calendar_id',
        'default_salary_structure_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(PayrollCalendar::class, 'payroll_calendar_id');
    }

    public function defaultSalaryStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'default_salary_structure_id');
    }
}
