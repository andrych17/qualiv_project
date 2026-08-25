<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §3M — one row per physical unit of a `tracking_mode = serial` product. `status` moves
 * in_stock -> issued as units leave (never back — a re-received unit is a new receipt line,
 * same serial number reused since it's the same physical item). `reserved` is a constant
 * reserved for §3N Reservations (not built yet) — nothing in this codebase sets it today.
 *
 * Cost attribution note: issuing a specific serial does NOT pull that unit's own receipt
 * cost — `CostingStrategyInterface` has no serial-level scoping (unlike batch_id, §3L), so
 * the ledger's `unit_cost` for a serial issue still follows the product's normal FIFO/
 * Average layer order. `stock_serials` is an identity/location registry, not a costing
 * dimension. If per-serial cost accuracy becomes a real requirement, it's an additive
 * migration later (a `serial_id` column on `stock_valuation_layers`), same shape as §3L.
 */
class StockSerial extends Model
{
    protected $table = 'INVENTORY.stock_serials';

    public const STATUS_IN_STOCK = 'in_stock';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_ISSUED = 'issued';

    protected $fillable = ['product_id', 'serial_number', 'status', 'warehouse_id', 'location_id', 'stock_ledger_id'];

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

    public function stockLedger()
    {
        return $this->belongsTo(StockLedger::class);
    }
}
