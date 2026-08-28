<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** §3D — manually captured (MVP) actual value for a KPI/subject/period; same polymorphic shape as Target. */
class KpiValue extends Model
{
    protected $table = 'PERF.kpi_values';

    public $timestamps = false;

    public const SOURCE_MANUAL = 'manual';

    public const SUBJECT_COMPANY = 'company';

    public const SUBJECT_ORG_UNIT = 'org_unit';

    public const SUBJECT_EMPLOYEE = 'employee';

    protected $fillable = ['kpi_id', 'subject_type', 'subject_id', 'period_id', 'actual_value', 'source', 'entered_by', 'entered_at'];

    protected $casts = [
        'actual_value' => 'decimal:4',
        'entered_at' => 'datetime',
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

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
