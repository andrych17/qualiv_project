<?php

namespace App\Modules\MES\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3E — discrete routing header; only one `is_active` version per product (DB partial unique index), same rule PP.pp_boms already uses. */
class Routing extends Model
{
    protected $table = 'MES.mes_routings';

    protected $fillable = ['product_id', 'version', 'is_active'];

    protected $casts = [
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

    public function ops()
    {
        return $this->hasMany(RoutingOp::class, 'routing_id');
    }
}
