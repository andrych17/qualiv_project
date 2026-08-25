<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/** §3L — a lot number, unique within its product. Expiry/manufacture/supplier are optional, captured once at receipt. */
class StockBatch extends Model
{
    protected $table = 'INVENTORY.stock_batches';

    protected $fillable = ['product_id', 'batch_number', 'expiry_date', 'manufacture_date', 'supplier_reference'];

    protected $casts = [
        'expiry_date' => 'date',
        'manufacture_date' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** True when this batch has expired as of the given date (defaults to today) — a document date, not necessarily "now". */
    public function isExpiredAsOf(?\DateTimeInterface $date = null): bool
    {
        if ($this->expiry_date === null) {
            return false;
        }

        return $this->expiry_date->lt($date ?? now());
    }
}
