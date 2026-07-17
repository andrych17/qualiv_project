<?php

// ponytail: Simple Eloquent model with namespace mapping and dynamic search query scopes

namespace App\Modules\Inventory\Models;

use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'inventory_category_id',
        'code',
        'name',
        'description',
        'stock',
        'minimum_stock',
        'unit',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (InventoryItem $item) {
            if (empty($item->uuid)) {
                $item->uuid = (string) Str::uuid();
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'inventory_category_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%');
            });
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }

    protected static function newFactory()
    {
        return InventoryItemFactory::new();
    }
}
