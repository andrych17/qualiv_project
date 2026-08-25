<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/** Denormalized current on-hand cache — always rebuildable from `stock_ledger` (§4). */
class StockBalance extends Model
{
    protected $table = 'INVENTORY.stock_balances';

    protected $fillable = ['product_id', 'warehouse_id', 'location_id', 'batch_id', 'qty_on_hand'];

    protected $casts = [
        'qty_on_hand' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class);
    }
}
