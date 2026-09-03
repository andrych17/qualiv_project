<?php

namespace App\Modules\PP\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** PP_SPECS.md §3B — one row per demand-generating event (manual entry, forecast row, Sales order, safety-stock shortfall). */
class DemandHeader extends Model
{
    protected $table = 'PP.pp_demand_hdrs';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_FORECAST = 'forecast';

    public const SOURCE_SALES_ORDER = 'sales_order';

    public const SOURCE_SAFETY_STOCK = 'safety_stock';

    public const SOURCE_BLANKET_ORDER = 'blanket_order';

    public const SOURCE_DEPENDENT = 'dependent';

    public const SOURCE_TRANSFER = 'transfer';

    protected $fillable = ['source_type', 'subject_type', 'subject_id', 'demand_date', 'note', 'created_by'];

    protected $casts = [
        'demand_date' => 'date',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['source_type'] ?? null, function ($query, $sourceType) {
            $query->where('source_type', $sourceType);
        });
    }

    public function lines()
    {
        return $this->hasMany(DemandLine::class, 'demand_hdr_id');
    }
}
