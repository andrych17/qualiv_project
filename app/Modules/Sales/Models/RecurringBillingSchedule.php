<?php

namespace App\Modules\Sales\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

class RecurringBillingSchedule extends Model
{
    protected $table = 'SALES.recurring_billing_schedules';

    protected $fillable = [
        'contr_subscription_id',
        'customer_id',
        'next_bill_date',
        'last_billed_at',
        'is_active',
    ];

    protected $casts = [
        'next_bill_date' => 'date',
        'last_billed_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function subscription()
    {
        return $this->belongsTo(ContractSubscription::class, 'contr_subscription_id');
    }

    public function customer()
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }
}
