<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Delivery extends Model
{
    protected $table = 'SALES.dlv_hdrs';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PICKED = 'picked';

    public const STATUS_PACKED = 'packed';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PICKED,
        self::STATUS_PACKED,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'uuid',
        'so_hdr_id',
        'status',
        'carrier',
        'tracking_number',
        'source_location_id',
        'inventory_goods_issue_id',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'source_location_id' => 'integer',
        'inventory_goods_issue_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'so_hdr_id');
    }

    public function lines()
    {
        return $this->hasMany(DeliveryLine::class, 'dlv_hdr_id');
    }
}
