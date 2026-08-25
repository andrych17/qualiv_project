<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    protected $table = 'INVENTORY.product_categories';

    protected $fillable = ['parent_category_id', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('name', 'ilike', '%'.$search.'%');
        })->when(($filters['status'] ?? null) !== null && $filters['status'] !== '', function ($query) use ($filters) {
            $query->where('is_active', $filters['status'] === 'active');
        });
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_category_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_category_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
