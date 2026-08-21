<?php

namespace App\Modules\WNE\Models;

use Illuminate\Database\Eloquent\Model;

class WrkflowStep extends Model
{
    protected $table = 'WNE.wrkflow_steps';

    public $timestamps = false;

    public const TYPE_APPROVAL = 'approval';

    public const TYPE_TASK = 'task';

    public const TYPE_CONDITION = 'condition';

    public const TYPE_PARALLEL_SPLIT = 'parallel_split';

    public const TYPE_PARALLEL_JOIN = 'parallel_join';

    public const TYPE_WEBHOOK_CALL = 'webhook_call';

    public const TYPE_WAIT_FOR_CALLBACK = 'wait_for_callback';

    public const TYPE_NOTIFY = 'notify';

    /** Waits for a human decision via completeTask() — §3C/§3H. */
    public const HUMAN_TYPES = [self::TYPE_APPROVAL, self::TYPE_TASK];

    /** No human action: "performing the action" IS evaluating/fanning out (or, for notify/webhook_call, firing a message/HTTP call — §3K/§3G), so the engine completes these itself, in the same transaction that begins them. */
    public const AUTO_ADVANCE_TYPES = [self::TYPE_CONDITION, self::TYPE_PARALLEL_SPLIT, self::TYPE_PARALLEL_JOIN, self::TYPE_NOTIFY, self::TYPE_WEBHOOK_CALL];

    /** Waits on something outside the engine's own control — a human decision resumes HUMAN_TYPES; this resumes only via a callback token hitting the inbound endpoint (§3G), or an SLA timeout failing it. */
    public const EXTERNAL_WAIT_TYPES = [self::TYPE_WAIT_FOR_CALLBACK];

    /** Step types the engine can execute today. */
    public const ENGINE_SUPPORTED_TYPES = [...self::HUMAN_TYPES, ...self::AUTO_ADVANCE_TYPES, ...self::EXTERNAL_WAIT_TYPES];

    protected $fillable = ['version_id', 'step_code', 'type', 'config', 'webhook_auth_headers', 'pos_x', 'pos_y', 'is_entry_step'];

    protected $casts = [
        'config' => 'array',
        'is_entry_step' => 'boolean',
        'webhook_auth_headers' => 'encrypted:array', // §3G: "auth header config (stored encrypted)" — kept off the plain `config` JSON deliberately
    ];

    public function version()
    {
        return $this->belongsTo(WrkflowVersion::class, 'version_id');
    }

    public function outgoingTransitions()
    {
        return $this->hasMany(WrkflowTransition::class, 'from_step_id')->orderBy('seq');
    }
}
