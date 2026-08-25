<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §3N — a soft hold against `stock_balances`, never touching `stock_ledger` itself (a
 * reservation promises stock, it doesn't move it). `location_id = null` means "unassigned,
 * pending pick" (§3O) and still reduces available-to-promise at every location in the
 * warehouse, since the pick could land at any of them.
 *
 * Known gap: nothing in Goods Issue/Transfer/Adjustment checks active reservations before
 * decrementing `stock_balances` (§3D/§3E/§3F/§3G weren't built with reservations in mind, and
 * this phase deliberately doesn't retrofit them — see INVENTORY_SPECS.md §3N, which scopes
 * itself to the availability query, not the posting guards). An unrelated Issue can drain
 * stock out from under an active reservation, and fulfillment of that reservation will then
 * fail with insufficient stock as a downstream surprise rather than an upfront block.
 */
class StockReservation extends Model
{
    protected $table = 'INVENTORY.stock_reservations';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'product_id', 'batch_id', 'serial_id', 'warehouse_id', 'location_id', 'qty',
        'subject_type', 'subject_id', 'status', 'expires_at', 'created_by',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'expires_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class);
    }

    public function serial()
    {
        return $this->belongsTo(StockSerial::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    /** Expiry is evaluated live everywhere ATP is computed — never trust `status` alone until the sweep has run (see ReservationService). */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
