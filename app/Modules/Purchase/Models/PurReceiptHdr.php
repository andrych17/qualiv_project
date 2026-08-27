<?php

namespace App\Modules\Purchase\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurReceiptHdr extends Model
{
    protected $table = 'PURCHASE.pur_receipt_hdrs';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'gr_no',
        'po_id',
        'receiver_id',
        'received_at',
        'warehouse_id',
        'location_id',
        'inventory_goods_receipt_id',
        'status',
        'discrepancy_notes',
    ];

    protected $casts = [
        'received_at' => 'datetime',
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
        return $this->belongsTo(PurOrderHdr::class, 'po_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function lines()
    {
        return $this->hasMany(PurReceiptLine::class, 'gr_id');
    }
}
