<?php

namespace App\Modules\PP\Models;

use Illuminate\Database\Eloquent\Model;

/** PP_SPECS.md §3D — one row per MRP execution; regenerative (each run replaces prior unreleased planned orders). */
class MrpRun extends Model
{
    protected $table = 'PP.pp_mrp_runs';

    public $timestamps = false;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['run_at', 'scenario_id', 'triggered_by', 'status'];

    protected $casts = [
        'run_at' => 'datetime',
    ];

    public function plannedOrders()
    {
        return $this->hasMany(PlannedOrder::class, 'mrp_run_id');
    }
}
