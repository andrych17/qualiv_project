<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

/** §3E — one measurable result under an Objective; see OkrProgressService for how it rolls up into the Objective's progress %. */
class OkrKeyResult extends Model
{
    protected $table = 'PERF.okr_key_results';

    public const METRIC_NUMERIC = 'numeric';

    public const METRIC_PERCENT = 'percent';

    public const METRIC_BOOLEAN = 'boolean';

    public const METRIC_MILESTONE = 'milestone';

    public const METRIC_TYPES = [self::METRIC_NUMERIC, self::METRIC_PERCENT, self::METRIC_BOOLEAN, self::METRIC_MILESTONE];

    protected $fillable = ['okr_id', 'description', 'metric_type', 'start_value', 'current_value', 'target_value', 'weight'];

    protected $casts = [
        'start_value' => 'decimal:4',
        'current_value' => 'decimal:4',
        'target_value' => 'decimal:4',
        'weight' => 'decimal:2',
    ];

    public function objective()
    {
        return $this->belongsTo(OkrObjective::class, 'okr_id');
    }
}
