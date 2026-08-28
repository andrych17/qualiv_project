<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * §3I — earned-badge log entry. No `timestamps()`; `earned_at` is the only time field
 * (matches BudgetActual's precedent for facts that happen once and are never edited).
 */
class Achievement extends Model
{
    protected $table = 'PERF.achievements';

    public $timestamps = false;

    public const SUBJECT_COMPANY = 'company';

    public const SUBJECT_ORG_UNIT = 'org_unit';

    public const SUBJECT_EMPLOYEE = 'employee';

    protected $fillable = [
        'subject_type', 'subject_id', 'badge_id', 'kpi_id', 'okr_id', 'period_id', 'earned_at', 'awarded_by',
    ];

    protected $casts = [
        'earned_at' => 'datetime',
    ];

    public function badge()
    {
        return $this->belongsTo(BadgeDefinition::class, 'badge_id');
    }

    public function kpi()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_id');
    }

    public function okr()
    {
        return $this->belongsTo(OkrObjective::class, 'okr_id');
    }

    public function period()
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function awardedBy()
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }
}
