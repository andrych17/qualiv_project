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

    /** No human action: "performing the action" IS evaluating/fanning out, so the engine completes these itself, in the same transaction that begins them (§3D). */
    public const AUTO_ADVANCE_TYPES = [self::TYPE_CONDITION, self::TYPE_PARALLEL_SPLIT, self::TYPE_PARALLEL_JOIN];

    /** Step types the engine can execute today — webhook_call/wait_for_callback/notify need §3G/§3I first. */
    public const ENGINE_SUPPORTED_TYPES = [...self::HUMAN_TYPES, ...self::AUTO_ADVANCE_TYPES];

    protected $fillable = ['version_id', 'step_code', 'type', 'config', 'pos_x', 'pos_y', 'is_entry_step'];

    protected $casts = [
        'config' => 'array',
        'is_entry_step' => 'boolean',
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
