<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3D — how much of a payment was applied to a specific invoice. */
class ArPaymentApplication extends Model
{
    protected $table = 'ACCOUNTING.ar_payment_applications';

    public $timestamps = false;

    protected $fillable = ['ar_payment_id', 'ar_invoice_id', 'applied_amount'];

    protected $casts = [
        'applied_amount' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(ArPayment::class, 'ar_payment_id');
    }

    public function invoice()
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }
}
