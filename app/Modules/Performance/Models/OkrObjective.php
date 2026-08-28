<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** §3E — a goal owned by a subject (company/org_unit/employee) for one OKR cycle, optionally aligned under a parent Objective. */
class OkrObjective extends Model
{
    protected $table = 'PERF.okr_objectives';

    public const SUBJECT_COMPANY = 'company';

    public const SUBJECT_ORG_UNIT = 'org_unit';

    public const SUBJECT_EMPLOYEE = 'employee';

    public const STATUS_ON_TRACK = 'on_track';

    public const STATUS_AT_RISK = 'at_risk';

    public const STATUS_OFF_TRACK = 'off_track';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [self::STATUS_ON_TRACK, self::STATUS_AT_RISK, self::STATUS_OFF_TRACK, self::STATUS_COMPLETED];

    protected $fillable = ['cycle_id', 'subject_type', 'subject_id', 'objective_text', 'parent_okr_id', 'status', 'created_by'];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['cycle_id'] ?? null, function ($query, $cycleId) {
            $query->where('cycle_id', $cycleId);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        })->when($filters['subject_type'] ?? null, function ($query, $subjectType) {
            $query->where('subject_type', $subjectType);
        });
    }

    public function cycle()
    {
        return $this->belongsTo(OkrCycle::class, 'cycle_id');
    }

    public function keyResults()
    {
        return $this->hasMany(OkrKeyResult::class, 'okr_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_okr_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_okr_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
