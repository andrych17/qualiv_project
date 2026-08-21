<?php

namespace App\Modules\WNE\Models;

use Illuminate\Database\Eloquent\Model;

class WrkflowSlaRule extends Model
{
    protected $table = 'WNE.wrkflow_sla_rules';

    public $timestamps = false;

    public const ACTION_REASSIGN_TO_ROLE = 'reassign_to_role';

    public const ACTION_NOTIFY_MANAGER_OF_ASSIGNEE = 'notify_manager_of_assignee';

    public const ACTION_NOTIFY_ROLE = 'notify_role';

    /** §3G: a wait_for_callback step whose external callback never arrived — genuinely fails the step (WorkflowService::failStep()), not just a notification. Usable on any step type, not only wait_for_callback. */
    public const ACTION_FAIL_STEP = 'fail_step';

    public const ACTIONS = [
        self::ACTION_REASSIGN_TO_ROLE, self::ACTION_NOTIFY_MANAGER_OF_ASSIGNEE, self::ACTION_NOTIFY_ROLE, self::ACTION_FAIL_STEP,
    ];

    protected $fillable = ['step_id', 'version_id', 'sla_hours', 'escalation_action', 'escalation_target'];

    public function step()
    {
        return $this->belongsTo(WrkflowStep::class, 'step_id');
    }

    public function version()
    {
        return $this->belongsTo(WrkflowVersion::class, 'version_id');
    }

    /** Step-specific rule wins; falls back to the version-level default (a rule with no step_id). */
    public static function resolveFor(WrkflowStep $step): ?self
    {
        return static::query()->where('step_id', $step->id)->first()
            ?? static::query()->whereNull('step_id')->where('version_id', $step->version_id)->first();
    }
}
