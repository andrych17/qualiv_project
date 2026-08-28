<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $table = 'INVENTORY.products';

    public const COSTING_FIFO = 'fifo';

    public const COSTING_AVERAGE = 'average';

    public const TRACKING_NONE = 'none';

    public const TRACKING_BATCH = 'batch';

    public const TRACKING_SERIAL = 'serial';

    public const ABC_A = 'A';

    public const ABC_B = 'B';

    public const ABC_C = 'C';

    protected $fillable = [
        'uuid', 'sku', 'name', 'description', 'category_id', 'base_uom_id',
        'costing_method', 'reorder_point', 'reorder_quantity', 'tracking_mode', 'abc_class', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'reorder_point' => 'decimal:4',
        'reorder_quantity' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
            }
        });
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('sku', 'ilike', '%'.$search.'%')
                    ->orWhere('name', 'ilike', '%'.$search.'%');
            });
        })->when(($filters['status'] ?? null) !== null && $filters['status'] !== '', function ($query) use ($filters) {
            $query->where('is_active', $filters['status'] === 'active');
        })->when($filters['category_id'] ?? null, function ($query, $categoryId) {
            $query->where('category_id', $categoryId);
        });
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function baseUom()
    {
        return $this->belongsTo(Uom::class, 'base_uom_id');
    }

    public function barcodes()
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function uomConversions()
    {
        return $this->hasMany(UomConversion::class);
    }
}
