<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3E — how much of a payment was applied to a specific bill. */
class ApPaymentApplication extends Model
{
    protected $table = 'ACCOUNTING.ap_payment_applications';

    public $timestamps = false;

    protected $fillable = ['ap_payment_id', 'ap_bill_id', 'applied_amount'];

    protected $casts = [
        'applied_amount' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(ApPayment::class, 'ap_payment_id');
    }

    public function bill()
    {
        return $this->belongsTo(ApBill::class, 'ap_bill_id');
    }
}
