<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** §3F — a named composition of KPI/OKR items, grouped by perspective, for a subject and period. */
class Scorecard extends Model
{
    protected $table = 'PERF.scorecard_hdrs';

    public const SUBJECT_COMPANY = 'company';

    public const SUBJECT_ORG_UNIT = 'org_unit';

    public const SUBJECT_EMPLOYEE = 'employee';

    protected $fillable = ['name', 'subject_type', 'subject_id', 'period_id', 'created_by'];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['subject_type'] ?? null, function ($query, $subjectType) {
            $query->where('subject_type', $subjectType);
        })->when($filters['period_id'] ?? null, function ($query, $periodId) {
            $query->where('period_id', $periodId);
        });
    }

    public function items()
    {
        return $this->hasMany(ScorecardItem::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
