<?php

namespace App\Modules\PP\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** PP_SPECS.md §3A — one planning-only companion row per INVENTORY.products item. */
class ItemPlanningParam extends Model
{
    protected $table = 'PP.pp_item_planning_params';

    public const MAKE_TO_STOCK = 'mts';

    public const MAKE_TO_ORDER = 'mto';

    protected $fillable = [
        'product_id',
        'make_type',
        'min_lot_qty',
        'max_lot_qty',
        'fixed_lot_qty',
        'economic_lot_qty',
        'safety_stock_qty',
        'lead_time_days',
        'planning_lead_time_days',
        'order_multiple',
        'scrap_pct',
        'yield_pct_override',
        'production_calendar_ref',
        'preferred_line_type',
        'preferred_line_ref_id',
        'alternate_line_ref_id',
        'planning_fence_days',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('product', function ($query) use ($search) {
                $query->where('sku', 'ilike', '%'.$search.'%')
                    ->orWhere('name', 'ilike', '%'.$search.'%');
            });
        })->when($filters['make_type'] ?? null, function ($query, $makeType) {
            $query->where('make_type', $makeType);
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
