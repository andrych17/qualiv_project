<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrderLine extends Model
{
    protected $table = 'SALES.so_lines';

    protected $fillable = [
        'so_hdr_id',
        'line_no',
        'item_type',
        'product_id',
        'description',
        'qty_ordered',
        'qty_delivered',
        'qty_invoiced',
        'unit_price',
        'discount_amount',
        'tax_amount',
        'line_total',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'qty_ordered' => 'decimal:3',
        'qty_delivered' => 'decimal:3',
        'qty_invoiced' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'product_id' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'so_hdr_id');
    }

    public function deliveryLines()
    {
        return $this->hasMany(DeliveryLine::class, 'so_line_id');
    }

    public function returnLines()
    {
        return $this->hasMany(SalesReturnLine::class, 'so_line_id');
    }
}
