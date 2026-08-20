<?php

namespace App\Modules\WNE\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WrkflowInstance extends Model
{
    protected $table = 'WNE.wrkflow_instances';

    public $timestamps = false;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Terminal statuses — cancel()/executeStep() must no-op past these, never re-drive a finished instance. */
    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    protected $fillable = [
        'uuid', 'definition_version_id', 'subject_type', 'subject_id',
        'status', 'payload', 'started_by', 'started_at', 'ended_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (WrkflowInstance $instance) {
            if (empty($instance->uuid)) {
                $instance->uuid = (string) Str::uuid();
            }
        });
    }

    public function definitionVersion()
    {
        return $this->belongsTo(WrkflowVersion::class, 'definition_version_id');
    }

    public function starter()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function steps()
    {
        return $this->hasMany(WrkflowInstanceStep::class, 'instance_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
