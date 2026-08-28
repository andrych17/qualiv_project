<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SalesOrder extends Model
{
    protected $table = 'SALES.so_hdrs';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PARTIALLY_FULFILLED = 'partially_fulfilled';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_CONFIRMED,
        self::STATUS_PARTIALLY_FULFILLED,
        self::STATUS_FULFILLED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'uuid',
        'so_number',
        'customer_id',
        'quote_id',
        'price_list_id',
        'status',
        'subject_type',
        'subject_id',
        'wne_workflow_instance_id',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function quote()
    {
        return $this->belongsTo(Quotation::class, 'quote_id');
    }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(SalesOrderLine::class, 'so_hdr_id')->orderBy('line_no');
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class, 'so_hdr_id')->orderByDesc('created_at');
    }

    public function returns()
    {
        return $this->hasMany(SalesReturn::class, 'so_hdr_id')->orderByDesc('created_at');
    }

    public function getSubtotalAttribute(): float
    {
        return (float) $this->lines->sum('line_total');
    }

    public function getTotalDiscountAttribute(): float
    {
        return (float) $this->lines->sum('discount_amount');
    }

    public function getTotalTaxAttribute(): float
    {
        return (float) $this->lines->sum('tax_amount');
    }

    public function getTotalAmountAttribute(): float
    {
        return (float) ($this->subtotal - $this->total_discount + $this->total_tax);
    }

    public function getQtyOrderedTotalAttribute(): float
    {
        return (float) $this->lines->sum('qty_ordered');
    }

    public function getQtyDeliveredTotalAttribute(): float
    {
        return (float) $this->lines->sum('qty_delivered');
    }

    public function getQtyInvoicedTotalAttribute(): float
    {
        return (float) $this->lines->sum('qty_invoiced');
    }
}
