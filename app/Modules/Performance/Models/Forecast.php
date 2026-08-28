<?php

namespace App\Modules\Performance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** §3H — a forecast trajectory linked to exactly one of a Budget or a KPI, for a subject and horizon. */
class Forecast extends Model
{
    protected $table = 'PERF.forecast_hdrs';

    public const SUBJECT_COMPANY = 'company';

    public const SUBJECT_ORG_UNIT = 'org_unit';

    public const SUBJECT_EMPLOYEE = 'employee';

    public const METHOD_MANUAL = 'manual';

    protected $fillable = [
        'subject_type', 'subject_id', 'budget_id', 'kpi_id', 'period_id',
        'method', 'version_no', 'root_forecast_id', 'is_latest', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_latest' => 'boolean',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['subject_type'] ?? null, function ($query, $subjectType) {
            $query->where('subject_type', $subjectType);
        });
    }

    /** Every version of this forecast belongs to the same series, keyed by its root (or itself, if it is the root). */
    public function scopeSeries(Builder $query, int $seriesId): void
    {
        $query->where(fn ($q) => $q->where('root_forecast_id', $seriesId)->orWhere('id', $seriesId));
    }

    public function lines()
    {
        return $this->hasMany(ForecastLine::class, 'forecast_id');
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function kpi()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_id');
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function rootForecast()
    {
        return $this->belongsTo(self::class, 'root_forecast_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
