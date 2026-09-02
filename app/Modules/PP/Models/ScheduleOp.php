<?php

namespace App\Modules\PP\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * PP_SPECS.md §3H — a finite-capacity, resource-and-time-specific proposal for one operation of
 * a production planned order. `resource_ref_id` is informational (MES isn't built) — see
 * migration docblock. Never written by MES.mes_prod_events (that stays execution-only).
 */
class ScheduleOp extends Model
{
    protected $table = 'PP.pp_schedule_ops';

    public const RESOURCE_TYPE_MES_WORK_CENTER = 'mes_work_center';

    public const RESOURCE_TYPE_MES_MACHINE = 'mes_machine';

    public const RESOURCE_TYPE_MES_STATION = 'mes_station';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'planned_order_id', 'seq', 'resource_type', 'resource_ref_id',
        'planned_start', 'planned_end', 'status', 'scenario_id',
    ];

    protected $casts = [
        'planned_start' => 'datetime',
        'planned_end' => 'datetime',
    ];

    public function scopeBaseline(Builder $query): void
    {
        $query->whereNull('scenario_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['resource_type'] ?? null, function ($query, $type) {
            $query->where('resource_type', $type);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        })->when($filters['planned_order_id'] ?? null, function ($query, $id) {
            $query->where('planned_order_id', $id);
        });
    }

    public function plannedOrder()
    {
        return $this->belongsTo(PlannedOrder::class);
    }
}
