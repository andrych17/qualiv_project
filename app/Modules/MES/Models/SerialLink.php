<?php

namespace App\Modules\MES\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockSerial;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3H — one row per component that went into one finished serial. MES doesn't own the serial identity (Inventory's `stock_serials` does) — only the parent→component linkage. */
class SerialLink extends Model
{
    protected $table = 'MES.mes_serial_links';

    public $timestamps = false;

    protected $fillable = [
        'serial_id', 'component_serial_id', 'component_lot_id', 'material_product_id', 'order_id', 'operation_ref', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function serial()
    {
        return $this->belongsTo(StockSerial::class, 'serial_id');
    }

    public function componentSerial()
    {
        return $this->belongsTo(StockSerial::class, 'component_serial_id');
    }

    public function componentLot()
    {
        return $this->belongsTo(StockBatch::class, 'component_lot_id');
    }

    public function material()
    {
        return $this->belongsTo(Product::class, 'material_product_id');
    }

    public function order()
    {
        return $this->belongsTo(ProdOrder::class, 'order_id');
    }
}
