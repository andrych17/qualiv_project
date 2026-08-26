<?php

namespace App\Modules\HCM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $table = 'HCM.shifts';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'is_active',
    ];

    protected $casts = [
        'break_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class, 'shift_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('name', 'ilike', '%'.$search.'%');
        })->when(isset($filters['is_active']) && $filters['is_active'] !== '', function ($query) use ($filters) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        });
    }
}
