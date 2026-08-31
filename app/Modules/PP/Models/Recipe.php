<?php

namespace App\Modules\PP\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** PP_SPECS.md §3D — process recipe/formula header; only one `is_active` version per product (DB partial unique index). */
class Recipe extends Model
{
    protected $table = 'PP.pp_recipes';

    protected $fillable = [
        'product_id', 'version', 'batch_size', 'uom_code',
        'expected_yield_pct', 'expected_waste_pct', 'effective_from', 'effective_to', 'is_active',
    ];

    protected $casts = [
        'batch_size' => 'decimal:4',
        'expected_yield_pct' => 'decimal:2',
        'expected_waste_pct' => 'decimal:2',
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

    public function ingredients()
    {
        return $this->hasMany(RecipeIngredient::class, 'recipe_id');
    }
}
