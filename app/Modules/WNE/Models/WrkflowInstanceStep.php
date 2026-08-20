<?php

namespace App\Modules\WNE\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class WrkflowInstanceStep extends Model
{
    protected $table = 'WNE.wrkflow_instance_steps';

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_WAITING_EXTERNAL = 'waiting_external';

    /** A step in one of these can still be re-driven by the recovery sweep or advanced by completeTask(). */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_WAITING_EXTERNAL];

    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_CANCELLED];

    protected $fillable = [
        'instance_id', 'step_id', 'status', 'assigned_to', 'assigned_role', 'assigned_team_id',
        'due_at', 'attempt', 'idempotency_key', 'started_at', 'completed_at', 'decision', 'comment',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function instance()
    {
        return $this->belongsTo(WrkflowInstance::class, 'instance_id');
    }

    public function step()
    {
        return $this->belongsTo(WrkflowStep::class, 'step_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public static function idempotencyKey(int $instanceId, int $stepId, int $attempt): string
    {
        return "{$instanceId}:{$stepId}:{$attempt}";
    }
}
