<?php

namespace App\Modules\HCM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    protected $table = 'HCM.positions';

    protected $fillable = [
        'job_id',
        'org_unit_id',
        'reports_to_position_id',
        'headcount_cap',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'headcount_cap' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'reports_to_position_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Position::class, 'reports_to_position_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'position_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('job', function ($q) use ($search) {
                $q->where('title', 'ilike', '%'.$search.'%')
                    ->orWhere('code', 'ilike', '%'.$search.'%');
            })->orWhereHas('orgUnit', function ($q) use ($search) {
                $q->where('name', 'ilike', '%'.$search.'%');
            });
        })->when($filters['org_unit_id'] ?? null, function ($query, $orgUnitId) {
            $query->where('org_unit_id', $orgUnitId);
        })->when(isset($filters['is_active']) && $filters['is_active'] !== '', function ($query) use ($filters) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        });
    }
}
