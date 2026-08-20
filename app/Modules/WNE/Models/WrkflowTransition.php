<?php

namespace App\Modules\WNE\Models;

use Illuminate\Database\Eloquent\Model;

class WrkflowTransition extends Model
{
    protected $table = 'WNE.wrkflow_transitions';

    public $timestamps = false;

    protected $fillable = ['from_step_id', 'to_step_id', 'condition_expression', 'seq'];

    protected $casts = [
        'condition_expression' => 'array',
    ];

    public function fromStep()
    {
        return $this->belongsTo(WrkflowStep::class, 'from_step_id');
    }

    public function toStep()
    {
        return $this->belongsTo(WrkflowStep::class, 'to_step_id');
    }

    /** NULL condition = the mandatory default/"else" transition (§3D). */
    public function isDefault(): bool
    {
        return $this->condition_expression === null;
    }
}
