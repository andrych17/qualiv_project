<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class ReimbursementCategory extends Model
{
    protected $table = 'PAYROLL.reimbursement_categories';

    protected $fillable = [
        'code',
        'name',
        'max_claim_amount',
        'requires_receipt',
        'is_active',
    ];

    protected $casts = [
        'max_claim_amount' => 'decimal:2',
        'requires_receipt' => 'boolean',
        'is_active' => 'boolean',
    ];
}
