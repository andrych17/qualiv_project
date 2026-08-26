<?php

namespace App\Modules\HCM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $table = 'HCM.leave_balances';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'period_year',
        'entitled_days',
        'used_days',
        'carried_over_days',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'entitled_days' => 'decimal:2',
        'used_days' => 'decimal:2',
        'carried_over_days' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function getRemainingDaysAttribute(): float
    {
        return (float) (($this->entitled_days + $this->carried_over_days) - $this->used_days);
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['employee_id'] ?? null, function ($query, $employeeId) {
            $query->where('employee_id', $employeeId);
        })->when($filters['period_year'] ?? null, function ($query, $year) {
            $query->where('period_year', $year);
        })->when($filters['leave_type_id'] ?? null, function ($query, $typeId) {
            $query->where('leave_type_id', $typeId);
        });
    }
}
