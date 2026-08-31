<?php

namespace App\Modules\PP\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** PP_SPECS.md §3B/§5 — `scenario_id` null is the baseline plan every other engine reads by default. */
class DemandLine extends Model
{
    protected $table = 'PP.pp_demand_lines';

    protected $fillable = ['demand_hdr_id', 'product_id', 'need_by_date', 'qty', 'scenario_id'];

    protected $casts = [
        'need_by_date' => 'date',
        'qty' => 'decimal:4',
    ];

    public function scopeBaseline(Builder $query): void
    {
        $query->whereNull('scenario_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['product_id'] ?? null, function ($query, $productId) {
            $query->where('product_id', $productId);
        })->when($filters['source_type'] ?? null, function ($query, $sourceType) {
            $query->whereHas('header', fn ($query) => $query->where('source_type', $sourceType));
        });
    }

    public function header()
    {
        return $this->belongsTo(DemandHeader::class, 'demand_hdr_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
