<?php

namespace App\Modules\PP\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * PP_SPECS.md §3F — one row per resource-or-resource-group / period / scenario. Rough-cut only
 * (Phase 1): `required_hours`/`available_hours` are planner-entered, not auto-computed (no MES
 * routing standard times, no Schedule hours-aggregator exist yet — see CapacityPlanService).
 */
class CapacityPlan extends Model
{
    protected $table = 'PP.pp_capacity_plans';

    const UPDATED_AT = null;

    public const RESOURCE_TYPE_MES_WORK_CENTER = 'mes_work_center';

    public const RESOURCE_TYPE_MES_MACHINE = 'mes_machine';

    public const RESOURCE_TYPE_PP_RESOURCE = 'pp_resource';

    protected $fillable = [
        'resource_group_id', 'resource_type', 'resource_ref_id',
        'period_start', 'period_end', 'required_hours', 'available_hours', 'scenario_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'required_hours' => 'decimal:2',
        'available_hours' => 'decimal:2',
    ];

    public function scopeBaseline(Builder $query): void
    {
        $query->whereNull('scenario_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['period_start'] ?? null, function ($query, $periodStart) {
            $query->where('period_start', $periodStart);
        });
    }

    public function resourceGroup()
    {
        return $this->belongsTo(ResourceGroup::class, 'resource_group_id');
    }

    /** Only meaningful when resource_type === RESOURCE_TYPE_PP_RESOURCE — see class docblock. */
    public function ppResource()
    {
        return $this->belongsTo(Resource::class, 'resource_ref_id');
    }
}
