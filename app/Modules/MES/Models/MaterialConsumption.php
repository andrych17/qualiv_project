<?php

namespace App\Modules\MES\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockSerial;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3J — one row per `InventoryService::issue()`/`receive()` call MES makes on an order's behalf; MES never writes `INVENTORY.stock_ledger` directly. */
class MaterialConsumption extends Model
{
    protected $table = 'MES.mes_material_consumptions';

    public $timestamps = false;

    public const TYPE_ISSUE = 'issue';

    public const TYPE_RETURN = 'return';

    protected $fillable = [
        'order_id', 'operation_ref', 'material_product_id', 'lot_id', 'serial_id', 'qty', 'uom_code', 'type', 'created_at',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(ProdOrder::class, 'order_id');
    }

    public function material()
    {
        return $this->belongsTo(Product::class, 'material_product_id');
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
