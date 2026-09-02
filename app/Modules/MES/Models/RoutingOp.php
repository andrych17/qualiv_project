<?php

namespace App\Modules\MES\Models;

use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3E — one execution step in a discrete routing; ordered by `seq`. */
class RoutingOp extends Model
{
    protected $table = 'MES.mes_routing_ops';

    public $timestamps = false;

    protected $fillable = [
        'routing_id', 'seq', 'op_code', 'op_name', 'work_center_id',
        'setup_time_minutes', 'run_time_minutes', 'queue_time_minutes',
        'standard_output_qty', 'instructions', 'auto_issue_components', 'is_rework_destination',
    ];

    protected $casts = [
        'standard_output_qty' => 'decimal:4',
        'auto_issue_components' => 'boolean',
        'is_rework_destination' => 'boolean',
    ];

    public function routing()
    {
        return $this->belongsTo(Routing::class, 'routing_id');
    }

    public function workCenter()
    {
        return $this->belongsTo(WorkCenter::class, 'work_center_id');
    }
}
