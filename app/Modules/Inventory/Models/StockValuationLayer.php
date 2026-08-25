<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §3J cost layers. FIFO creates one row per receipt, consumed oldest-first. Weighted
 * Average keeps exactly one open row per product/warehouse, re-priced on every receipt —
 * see AverageStrategy for why (issues must not change the running average).
 */
class StockValuationLayer extends Model
{
    protected $table = 'INVENTORY.stock_valuation_layers';

    protected $fillable = ['product_id', 'warehouse_id', 'batch_id', 'stock_ledger_id', 'unit_cost', 'qty', 'remaining_qty'];

    protected $casts = [
        'unit_cost' => 'decimal:6',
        'qty' => 'decimal:4',
        'remaining_qty' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class);
    }
}
