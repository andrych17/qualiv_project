<?php

namespace App\Modules\MES\Models;

use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3F — spec/limit definition for one phase parameter; actual readings live in `mes_batch_parameter_readings` (§3I), built later. */
class ProcessParameter extends Model
{
    protected $table = 'MES.mes_process_parameters';

    public $timestamps = false;

    protected $fillable = ['process_phase_id', 'parameter_code', 'target_value', 'min_value', 'max_value', 'uom_code'];

    protected $casts = [
        'target_value' => 'decimal:4',
        'min_value' => 'decimal:4',
        'max_value' => 'decimal:4',
    ];

    public function phase()
    {
        return $this->belongsTo(ProcessPhase::class, 'process_phase_id');
    }
}
