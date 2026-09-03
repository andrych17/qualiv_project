<?php

namespace App\Modules\MES\Models;

use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3I — one phase's execution against a batch; materialized from `mes_process_phases` (§3F) at batch creation, one row per phase in sequence. */
class BatchPhase extends Model
{
    protected $table = 'MES.mes_batch_phases';

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = ['batch_id', 'process_phase_id', 'seq', 'status', 'start_at', 'end_at', 'operator_id', 'machine_id'];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(MesBatch::class, 'batch_id');
    }

    public function processPhase()
    {
        return $this->belongsTo(ProcessPhase::class, 'process_phase_id');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function readings()
    {
        return $this->hasMany(BatchParameterReading::class, 'batch_phase_id');
    }
}
