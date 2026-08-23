<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3F — a cash/bank account, one-to-one with its GL cash account. */
class BankAccount extends Model
{
    protected $table = 'ACCOUNTING.bank_accounts';

    protected $fillable = [
        'company_id', 'name', 'bank_name', 'account_number', 'account_holder_name',
        'currency_code', 'gl_account_id', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function glAccount()
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }
}
