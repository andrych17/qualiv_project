<?php

namespace App\Modules\MES\Models;

use App\Models\User;
use App\Modules\HCM\Models\ShiftAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * MES_SPECS.md §3P — the one MES-owned table for shift handover; `shift_assignment_id` is a
 * read-only reference into HCM's own `shifts`/`shift_assignments` (no MES-owned shift model).
 */
class ShiftHandoverNote extends Model
{
    protected $table = 'MES.mes_shift_handover_notes';

    public $timestamps = false;

    protected $fillable = ['shift_assignment_id', 'order_summary', 'notes', 'created_by', 'created_at'];

    protected $casts = [
        'order_summary' => 'array',
        'created_at' => 'datetime',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['shift_assignment_id'] ?? null, function ($query, $shiftAssignmentId) {
            $query->where('shift_assignment_id', $shiftAssignmentId);
        })->when($filters['work_date'] ?? null, function ($query, $workDate) {
            $query->whereHas('shiftAssignment', fn ($q) => $q->where('work_date', $workDate));
        });
    }

    public function shiftAssignment()
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
