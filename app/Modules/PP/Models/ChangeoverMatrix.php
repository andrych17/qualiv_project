<?php

namespace App\Modules\PP\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * PP_SPECS.md §3J — one row of the Setup & Changeover Matrix: the cost of switching a resource
 * group from one product/family to another. `'other'` in `from_family`/`to_family` is a literal
 * wildcard tag (PP_SPECS.sql §5), not a real category — see ChangeoverMatrixService::lookup().
 */
class ChangeoverMatrix extends Model
{
    protected $table = 'PP.pp_changeover_matrix';

    public const WILDCARD_FAMILY = 'other';

    protected $fillable = [
        'from_product_id', 'from_family', 'to_product_id', 'to_family',
        'resource_group_id', 'changeover_minutes', 'cleaning_minutes', 'is_active',
    ];

    protected $casts = [
        'changeover_minutes' => 'integer',
        'cleaning_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['resource_group_id'] ?? null, function ($query, $id) {
            $query->where('resource_group_id', $id);
        })->when(($filters['status'] ?? null) !== null && $filters['status'] !== '', function ($query) use ($filters) {
            $query->where('is_active', $filters['status'] === 'active');
        });
    }

    public function fromProduct()
    {
        return $this->belongsTo(Product::class, 'from_product_id');
    }

    public function toProduct()
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }

    public function resourceGroup()
    {
        return $this->belongsTo(ResourceGroup::class, 'resource_group_id');
    }
}
