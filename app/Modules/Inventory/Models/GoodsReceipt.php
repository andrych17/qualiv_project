<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GoodsReceipt extends Model
{
    protected $table = 'INVENTORY.goods_receipts';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'uuid', 'warehouse_id', 'receipt_date', 'subject_type', 'subject_id',
        'reference_number', 'status', 'posted_at', 'created_by',
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (GoodsReceipt $receipt) {
            if (empty($receipt->uuid)) {
                $receipt->uuid = (string) Str::uuid();
            }
        });
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('reference_number', 'ilike', '%'.$search.'%');
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        })->when($filters['warehouse_id'] ?? null, function ($query, $warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        });
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
