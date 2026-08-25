<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    protected $table = 'INVENTORY.goods_receipt_lines';

    public $timestamps = false;

    protected $fillable = ['goods_receipt_id', 'product_id', 'batch_id', 'qty', 'uom_id', 'unit_cost', 'destination_location_id', 'serial_numbers'];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_cost' => 'decimal:6',
        'serial_numbers' => 'array',
    ];

    public function receipt()
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class);
    }

    public function destinationLocation()
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class);
    }
}
