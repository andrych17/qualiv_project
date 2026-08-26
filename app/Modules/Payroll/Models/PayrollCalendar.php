<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollCalendar extends Model
{
    protected $table = 'PAYROLL.payroll_calendars';

    protected $fillable = [
        'name',
        'pay_frequency',
        'cutoff_day',
        'pay_day',
        'shift_earlier_on_holiday',
        'is_active',
    ];

    protected $casts = [
        'cutoff_day' => 'integer',
        'pay_day' => 'integer',
        'shift_earlier_on_holiday' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function payrollGroups(): HasMany
    {
        return $this->hasMany(PayrollGroup::class, 'payroll_calendar_id');
    }
}
