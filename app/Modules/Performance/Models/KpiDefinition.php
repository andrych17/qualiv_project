<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** §3C — tenant-defined KPI metric library, same pattern as CRM's `partner_role_types`. */
class KpiDefinition extends Model
{
    protected $table = 'PERF.kpi_definitions';

    public const UNIT_NUMBER = 'number';

    public const UNIT_PERCENT = 'percent';

    public const UNIT_CURRENCY = 'currency';

    public const UNIT_RATIO = 'ratio';

    public const DIRECTION_HIGHER_IS_BETTER = 'higher_is_better';

    public const DIRECTION_LOWER_IS_BETTER = 'lower_is_better';

    protected $fillable = ['name', 'unit', 'direction', 'perspective_id', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('name', 'ilike', '%'.$search.'%');
        })->when($filters['perspective_id'] ?? null, function ($query, $perspectiveId) {
            $query->where('perspective_id', $perspectiveId);
        })->when(($filters['status'] ?? null) !== null && $filters['status'] !== '', function ($query) use ($filters) {
            $query->where('is_active', $filters['status'] === 'active');
        });
    }

    public function perspective()
    {
        return $this->belongsTo(Perspective::class);
    }

    public function targets()
    {
        return $this->hasMany(Target::class, 'kpi_id');
    }
}
