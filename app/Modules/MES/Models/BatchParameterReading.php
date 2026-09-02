<?php

namespace App\Modules\MES\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3I — the actual-value counterpart to `mes_process_parameters`'s target/min/max; one row per operator-entered (or, in Phase 3, IoT-sourced) reading. */
class BatchParameterReading extends Model
{
    protected $table = 'MES.mes_batch_parameter_readings';

    public $timestamps = false;

    protected $fillable = ['batch_phase_id', 'process_parameter_id', 'value', 'recorded_at', 'recorded_by', 'machine_id'];

    protected $casts = [
        'value' => 'decimal:4',
        'recorded_at' => 'datetime',
    ];

    public function phase()
    {
        return $this->belongsTo(BatchPhase::class, 'batch_phase_id');
    }

    public function parameter()
    {
        return $this->belongsTo(ProcessParameter::class, 'process_parameter_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }
}
