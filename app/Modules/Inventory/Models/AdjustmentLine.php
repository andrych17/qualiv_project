<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class AdjustmentLine extends Model
{
    protected $table = 'INVENTORY.adjustment_lines';

    public $timestamps = false;

    protected $fillable = ['adjustment_id', 'product_id', 'batch_id', 'system_qty', 'counted_qty'];

    protected $casts = [
        'system_qty' => 'decimal:4',
        'counted_qty' => 'decimal:4',
    ];

    public function adjustment()
    {
        return $this->belongsTo(Adjustment::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class);
    }
}
