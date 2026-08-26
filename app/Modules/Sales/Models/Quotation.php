<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Quotation extends Model
{
    protected $table = 'SALES.quot_hdrs';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CONVERTED = 'converted';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_APPROVED,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
        self::STATUS_EXPIRED,
        self::STATUS_CONVERTED,
    ];

    protected $fillable = [
        'uuid',
        'quote_group_id',
        'revision_no',
        'customer_id',
        'opportunity_id',
        'price_list_id',
        'validity_date',
        'status',
        'subject_type',
        'subject_id',
        'wne_workflow_instance_id',
        'document_id',
        'converted_so_id',
        'created_by',
    ];

    protected $casts = [
        'revision_no' => 'integer',
        'validity_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->quote_group_id)) {
                $model->quote_group_id = (string) Str::uuid();
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function opportunity()
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id');
    }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function convertedSalesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'converted_so_id');
    }

    public function lines()
    {
        return $this->hasMany(QuotationLine::class, 'quot_hdr_id')->orderBy('line_no');
    }

    public function revisions()
    {
        return $this->hasMany(Quotation::class, 'quote_group_id', 'quote_group_id')
            ->orderByDesc('revision_no');
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
}
