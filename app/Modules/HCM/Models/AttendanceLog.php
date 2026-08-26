<?php

namespace App\Modules\HCM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceLog extends Model
{
    protected $table = 'HCM.attendance_logs';

    public $timestamps = false;

    public const EXCEPTION_ON_TIME = 'on_time';

    public const EXCEPTION_LATE = 'late';

    public const EXCEPTION_ABSENT = 'absent';

    protected $fillable = [
        'employee_id',
        'clock_in_at',
        'clock_out_at',
        'source',
        'exception_flag',
        'created_at',
    ];

    protected $casts = [
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class, 'attendance_log_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'ilike', '%'.$search.'%')
                    ->orWhere('employee_no', 'ilike', '%'.$search.'%');
            });
        })->when($filters['employee_id'] ?? null, function ($query, $employeeId) {
            $query->where('employee_id', $employeeId);
        })->when($filters['exception_flag'] ?? null, function ($query, $flag) {
            $query->where('exception_flag', $flag);
        })->when($filters['date'] ?? null, function ($query, $date) {
            $query->whereDate('clock_in_at', $date);
        });
    }
}
