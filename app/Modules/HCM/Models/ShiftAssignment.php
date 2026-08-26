<?php

namespace App\Modules\HCM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftAssignment extends Model
{
    protected $table = 'HCM.shift_assignments';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'shift_id',
        'work_date',
    ];

    protected $casts = [
        'work_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'ilike', '%'.$search.'%')
                    ->orWhere('employee_no', 'ilike', '%'.$search.'%');
            });
        })->when($filters['work_date'] ?? null, function ($query, $date) {
            $query->where('work_date', $date);
        })->when($filters['shift_id'] ?? null, function ($query, $shiftId) {
            $query->where('shift_id', $shiftId);
        });
    }
}
