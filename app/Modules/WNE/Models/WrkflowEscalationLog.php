<?php

namespace App\Modules\WNE\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only — no update()/delete() call site should ever be added to this model. */
class WrkflowEscalationLog extends Model
{
    protected $table = 'WNE.wrkflow_escalation_log';

    public $timestamps = false;

    protected $fillable = ['instance_step_id', 'sla_rule_id', 'escalated_to_user_id', 'escalated_to_role', 'escalated_at'];

    protected $casts = [
        'escalated_at' => 'datetime',
    ];

    public function instanceStep()
    {
        return $this->belongsTo(WrkflowInstanceStep::class, 'instance_step_id');
    }

    public function slaRule()
    {
        return $this->belongsTo(WrkflowSlaRule::class, 'sla_rule_id');
    }
}
