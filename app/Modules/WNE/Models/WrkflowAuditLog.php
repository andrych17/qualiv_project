<?php

namespace App\Modules\WNE\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Append-only — no update/delete at the app layer (same convention as DMS.access_logs). */
class WrkflowAuditLog extends Model
{
    protected $table = 'WNE.wrkflow_audit_logs';

    public $timestamps = false;

    public const EVENT_INSTANCE_STARTED = 'instance_started';

    public const EVENT_STEP_COMPLETED = 'step_completed';

    public const EVENT_STEP_FAILED = 'step_failed';

    public const EVENT_INSTANCE_COMPLETED = 'instance_completed';

    public const EVENT_INSTANCE_FAILED = 'instance_failed';

    public const EVENT_INSTANCE_CANCELLED = 'instance_cancelled';

    public const EVENT_STEP_RECOVERED = 'step_recovered';

    protected $fillable = ['instance_id', 'instance_step_id', 'event_type', 'actor_id', 'detail', 'created_at'];

    protected $casts = [
        'detail' => 'array',
        'created_at' => 'datetime',
    ];

    public function instance()
    {
        return $this->belongsTo(WrkflowInstance::class, 'instance_id');
    }

    public function instanceStep()
    {
        return $this->belongsTo(WrkflowInstanceStep::class, 'instance_step_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
