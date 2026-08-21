<?php

namespace App\Modules\WNE\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WrkflowInstanceStep extends Model
{
    protected $table = 'WNE.wrkflow_instance_steps';

    public $timestamps = false;

    /** §3A/§3H "due soon" window — own copy, not CRM's HasSlaState (WNE must not depend on a Vertical-adjacent Core module's Concern; different column name/status vocabulary anyway). */
    public const DUE_SOON_HOURS = 24;

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
        'callback_token', 'callback_used_at',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'callback_used_at' => 'datetime',
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

    /**
     * §3H assignability: direct assignment always wins; a role-assigned step needs the user in
     * that SYSCONFIG group; a step with no resolvable target — team-assigned (assigned_team_id
     * has no backing table yet, §5) or genuinely unassigned — is open to any actor rather than
     * disappearing into nobody's inbox. `scopeAssignedToUser()` is the exact SQL mirror of this,
     * same convention as CRM's HasSlaState (SQL scope + PHP accessor, one source of truth) — so
     * "shows in the inbox" and "may act on it" (WorkflowService::completeTask()'s guard) can
     * never drift apart.
     *
     * @param  string[]  $groupCodes  the acting user's SYSCONFIG.config_grpusers.group_code values
     */
    public function isActionableBy(int $userId, array $groupCodes): bool
    {
        if ($this->assigned_to !== null) {
            return $this->assigned_to === $userId;
        }

        if ($this->assigned_role !== null) {
            return in_array($this->assigned_role, $groupCodes, true);
        }

        return true; // team-assigned or fully unassigned
    }

    /** SQL mirror of isActionableBy() — see that method's docblock. */
    public function scopeAssignedToUser(Builder $query, int $userId, array $groupCodes): Builder
    {
        return $query->where(function (Builder $q) use ($userId, $groupCodes) {
            $q->where('assigned_to', $userId)
                ->orWhere(fn (Builder $unassigned) => $unassigned->whereNull('assigned_to')->whereNull('assigned_role')->whereNull('assigned_team_id'))
                ->orWhereNotNull('assigned_team_id');

            if ($groupCodes !== []) {
                $q->orWhereIn('assigned_role', $groupCodes);
            }
        });
    }

    /** §3A/§3H Status Rail bucket — PHP mirror of scopeUrgencyFirst()'s SQL, same "breach surfaces first" convention as CRM's sla_state. */
    public function getSlaStateAttribute(): ?string
    {
        if ($this->due_at === null) {
            return null;
        }

        if ($this->due_at->isPast()) {
            return 'breached';
        }

        if ($this->due_at->lessThanOrEqualTo(now()->addHours(self::DUE_SOON_HOURS))) {
            return 'due_soon';
        }

        return 'on_track';
    }

    /** §3A: "SLA breaches surface first regardless of sort" — orders breached < due_soon < on_track < no SLA, ahead of any caller-chosen secondary sort. */
    public function scopeUrgencyFirst(Builder $query): Builder
    {
        $now = now();
        $dueSoon = $now->copy()->addHours(self::DUE_SOON_HOURS);

        return $query->orderByRaw(
            'CASE
                WHEN due_at IS NOT NULL AND due_at < ? THEN 0
                WHEN due_at IS NOT NULL AND due_at <= ? THEN 1
                WHEN due_at IS NOT NULL THEN 2
                ELSE 3
            END',
            [$now, $dueSoon]
        );
    }

    /** SQL form of the getSlaStateAttribute() buckets, for the inbox's own sla_state filter. */
    public function scopeFilterSlaState(Builder $query, ?string $state): Builder
    {
        if (! $state) {
            return $query;
        }

        $now = now();
        $dueSoon = $now->copy()->addHours(self::DUE_SOON_HOURS);

        return match ($state) {
            'breached' => $query->whereNotNull('due_at')->where('due_at', '<', $now),
            'due_soon' => $query->whereNotNull('due_at')->whereBetween('due_at', [$now, $dueSoon]),
            'on_track' => $query->whereNotNull('due_at')->where('due_at', '>', $dueSoon),
            'none' => $query->whereNull('due_at'),
            default => $query,
        };
    }
}
