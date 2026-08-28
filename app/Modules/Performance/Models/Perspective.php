<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** §3C — tenant-editable Balanced-Scorecard categories (Financial, Customer, Process, Learning & Growth, ...). */
class Perspective extends Model
{
    protected $table = 'PERF.perspectives';

    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('name', 'ilike', '%'.$search.'%');
        })->when(($filters['status'] ?? null) !== null && $filters['status'] !== '', function ($query) use ($filters) {
            $query->where('is_active', $filters['status'] === 'active');
        });
    }

    public function kpiDefinitions()
    {
        return $this->hasMany(KpiDefinition::class);
    }
}
