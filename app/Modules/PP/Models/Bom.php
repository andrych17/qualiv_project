<?php

namespace App\Modules\PP\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** PP_SPECS.md §3D — discrete BOM header; only one `is_active` version per product (DB partial unique index). */
class Bom extends Model
{
    protected $table = 'PP.pp_boms';

    protected $fillable = ['product_id', 'version', 'effective_from', 'effective_to', 'is_active'];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('product', function ($query) use ($search) {
                $query->where('sku', 'ilike', '%'.$search.'%')
                    ->orWhere('name', 'ilike', '%'.$search.'%');
            });
        });
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function lines()
    {
        return $this->hasMany(BomLine::class, 'bom_id');
    }
}
