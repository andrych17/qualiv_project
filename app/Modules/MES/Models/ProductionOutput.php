<?php

namespace App\Modules\MES\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockSerial;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3J — one row per `InventoryService::receive()` call MES makes to post finished/co-product/by-product/waste output into stock. */
class ProductionOutput extends Model
{
    protected $table = 'MES.mes_production_outputs';

    public $timestamps = false;

    public const TYPE_FINISHED = 'finished';

    public const TYPE_CO_PRODUCT = 'co_product';

    public const TYPE_BY_PRODUCT = 'by_product';

    public const TYPE_WASTE = 'waste';

    public const DISPOSITION_SCRAP = 'scrap';

    public const DISPOSITION_REWORK = 'rework';

    protected $fillable = [
        'order_id', 'operation_ref', 'output_type', 'product_id', 'qty', 'uom_code',
        'lot_id', 'serial_id', 'reason_code', 'disposition', 'created_at',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(ProdOrder::class, 'order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function lot()
    {
        return $this->belongsTo(StockBatch::class, 'lot_id');
    }

    public function serial()
    {
        return $this->belongsTo(StockSerial::class, 'serial_id');
    }
}
