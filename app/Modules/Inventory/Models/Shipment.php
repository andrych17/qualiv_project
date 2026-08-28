<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** §3P — header linking one or more packages (PackList), carrier, tracking, ship-confirm/deliver. */
class Shipment extends Model
{
    protected $table = 'INVENTORY.shipments';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    protected $fillable = [
        'uuid', 'warehouse_id', 'carrier', 'tracking_number', 'ship_date', 'status',
        'goods_issue_id', 'shipped_by', 'shipped_at', 'delivered_at', 'created_by',
    ];

    protected $casts = [
        'ship_date' => 'date',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Shipment $shipment) {
            if (empty($shipment->uuid)) {
                $shipment->uuid = (string) Str::uuid();
            }
        });
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function packLists()
    {
        return $this->hasMany(PackList::class);
    }

    public function goodsIssue()
    {
        return $this->belongsTo(GoodsIssue::class);
    }

    public function shippedBy()
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }
}
