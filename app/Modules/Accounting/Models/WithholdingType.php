<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3M PPh withholding types — MVP covers 23/4(2)/21; 22/15 are config-ready, not code changes. */
class WithholdingType extends Model
{
    protected $table = 'ACCOUNTING.withholding_types';

    public $timestamps = false;

    protected $fillable = ['company_id', 'code', 'bp_type', 'name', 'rate', 'is_final', 'gl_payable_account_id', 'is_active'];

    protected $casts = [
        'rate' => 'decimal:2',
        'is_final' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function glPayableAccount()
    {
        return $this->belongsTo(Account::class, 'gl_payable_account_id');
    }
}
