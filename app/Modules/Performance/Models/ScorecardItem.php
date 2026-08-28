<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

/** §3F — one row in a Scorecard: a weighted reference to exactly one of a KPI or an OKR Objective, under a perspective. */
class ScorecardItem extends Model
{
    protected $table = 'PERF.scorecard_items';

    protected $fillable = ['scorecard_id', 'perspective_id', 'kpi_id', 'okr_id', 'weight'];

    protected $casts = [
        'weight' => 'decimal:2',
    ];

    public function scorecard()
    {
        return $this->belongsTo(Scorecard::class);
    }

    public function perspective()
    {
        return $this->belongsTo(Perspective::class);
    }

    public function kpi()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_id');
    }

    public function okr()
    {
        return $this->belongsTo(OkrObjective::class, 'okr_id');
    }
}
