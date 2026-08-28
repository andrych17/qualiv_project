<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** §3C/§4 — fiscal period definitions shared by Targets and (later) Budgeting/Forecast/OKR cycles. */
class Period extends Model
{
    protected $table = 'PERF.periods';

    public const TYPE_YEAR = 'year';

    public const TYPE_QUARTER = 'quarter';

    public const TYPE_MONTH = 'month';

    protected $fillable = ['label', 'period_type', 'year', 'quarter', 'month', 'start_date', 'end_date', 'is_active'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['period_type'] ?? null, function ($query, $type) {
            $query->where('period_type', $type);
        })->when(($filters['status'] ?? null) !== null && $filters['status'] !== '', function ($query) use ($filters) {
            $query->where('is_active', $filters['status'] === 'active');
        });
    }

    public function targets()
    {
        return $this->hasMany(Target::class);
    }
}
