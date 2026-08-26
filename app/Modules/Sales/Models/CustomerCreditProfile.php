<?php

namespace App\Modules\Sales\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

class CustomerCreditProfile extends Model
{
    protected $table = 'SALES.customer_credit_profiles';

    protected $fillable = [
        'partner_id',
        'credit_limit',
        'payment_terms_days',
        'on_hold',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'payment_terms_days' => 'integer',
        'on_hold' => 'boolean',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
