<?php

namespace App\Modules\PP\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * PP_SPECS.md §3C — one period cell in the MPS grid. `is_frozen` is an edit lock on
 * `planned_qty` only — it does not (yet) block MRP from replanning the period, since §3D's MRP
 * engine nets one planned order per product per run, not per period (see MpsService docblock).
 */
class MpsLine extends Model
{
    protected $table = 'PP.pp_mps_lines';

    protected $fillable = [
        'mps_hdr_id', 'period_start', 'period_end', 'planned_qty', 'is_frozen',
        'released_planned_order_id', 'scenario_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'planned_qty' => 'decimal:4',
        'is_frozen' => 'boolean',
    ];

    public function scopeBaseline(Builder $query): void
    {
        $query->whereNull('scenario_id');
    }

    public function header()
    {
        return $this->belongsTo(MpsHeader::class, 'mps_hdr_id');
    }

    public function releasedPlannedOrder()
    {
        return $this->belongsTo(PlannedOrder::class, 'released_planned_order_id');
    }
}
