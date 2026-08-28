<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** §3C — assigns a KPI + target value to a subject (company/org_unit/employee) for a period; the "multi-level" mechanism. */
class Target extends Model
{
    protected $table = 'PERF.targets';

    public const SUBJECT_COMPANY = 'company';

    public const SUBJECT_ORG_UNIT = 'org_unit';

    public const SUBJECT_EMPLOYEE = 'employee';

    protected $fillable = ['kpi_id', 'subject_type', 'subject_id', 'period_id', 'target_value', 'stretch_value', 'notes', 'created_by'];

    protected $casts = [
        'target_value' => 'decimal:4',
        'stretch_value' => 'decimal:4',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['kpi_id'] ?? null, function ($query, $kpiId) {
            $query->where('kpi_id', $kpiId);
        })->when($filters['period_id'] ?? null, function ($query, $periodId) {
            $query->where('period_id', $periodId);
        })->when($filters['subject_type'] ?? null, function ($query, $subjectType) {
            $query->where('subject_type', $subjectType);
        });
    }

    public function kpi()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_id');
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
