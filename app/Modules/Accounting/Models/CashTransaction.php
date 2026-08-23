<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3F — a cash in/out entry not tied to an AR/AP document (petty cash, bank fees, interest). */
class CashTransaction extends Model
{
    protected $table = 'ACCOUNTING.cash_transactions';

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'uuid', 'company_id', 'bank_account_id', 'direction', 'transaction_date',
        'amount', 'offset_account_id', 'description', 'status', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function offsetAccount()
    {
        return $this->belongsTo(Account::class, 'offset_account_id');
    }
}
