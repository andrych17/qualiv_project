<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SalesReturn extends Model
{
    protected $table = 'SALES.ret_hdrs';

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_REPLACED = 'replaced';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_RECEIVED,
        self::STATUS_REFUNDED,
        self::STATUS_REPLACED,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'uuid',
        'so_hdr_id',
        'accounting_invoice_id',
        'customer_id',
        'reason_code',
        'status',
        'subject_type',
        'subject_id',
        'wne_workflow_instance_id',
        'replacement_so_id',
        'created_by',
    ];

    protected $casts = [
        'accounting_invoice_id' => 'integer',
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

    public function customer()
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function replacementOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'replacement_so_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(SalesReturnLine::class, 'ret_hdr_id');
    }
}
