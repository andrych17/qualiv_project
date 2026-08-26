<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryLine extends Model
{
    protected $table = 'SALES.dlv_lines';

    protected $fillable = [
        'dlv_hdr_id',
        'so_line_id',
        'qty_shipped',
    ];

    protected $casts = [
        'qty_shipped' => 'decimal:3',
    ];

    public function delivery()
    {
        return $this->belongsTo(Delivery::class, 'dlv_hdr_id');
    }

    public function salesOrderLine()
    {
        return $this->belongsTo(SalesOrderLine::class, 'so_line_id');
    }
}
