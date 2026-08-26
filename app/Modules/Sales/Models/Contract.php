<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Contract extends Model
{
    protected $table = 'SALES.contr_hdrs';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RENEWED = 'renewed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_RENEWED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    protected $fillable = [
        'uuid',
        'customer_id',
        'name',
        'term_start',
        'term_end',
        'auto_renew',
        'status',
        'price_list_id',
        'created_by',
    ];

    protected $casts = [
        'term_start' => 'date',
        'term_end' => 'date',
        'auto_renew' => 'boolean',
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

    public function priceList()
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subscriptions()
    {
        return $this->hasMany(ContractSubscription::class, 'contr_hdr_id')->orderBy('line_no');
    }
}
