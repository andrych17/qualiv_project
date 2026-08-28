<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class ContractSubscription extends Model
{
    protected $table = 'SALES.contr_subscriptions';

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_QUARTERLY = 'quarterly';

    public const INTERVAL_ANNUAL = 'annual';

    public const INTERVALS = [
        self::INTERVAL_MONTHLY,
        self::INTERVAL_QUARTERLY,
        self::INTERVAL_ANNUAL,
    ];

    protected $fillable = [
        'contr_hdr_id',
        'line_no',
        'item_type',
        'product_id',
        'description',
        'recurring_amount',
        'currency',
        'billing_interval',
        'is_active',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'recurring_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'product_id' => 'integer',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contr_hdr_id');
    }

    public function recurringSchedules()
    {
        return $this->hasMany(RecurringBillingSchedule::class, 'contr_subscription_id');
    }
}
