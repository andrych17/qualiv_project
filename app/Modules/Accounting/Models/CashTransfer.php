<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3F — funds moved between two of the company's own cash/bank accounts. Same-currency only in v1 (see CashTransferService). */
class CashTransfer extends Model
{
    protected $table = 'ACCOUNTING.cash_transfers';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'uuid', 'company_id', 'from_bank_account_id', 'to_bank_account_id', 'transfer_date',
        'amount', 'description', 'status', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function fromBankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'from_bank_account_id');
    }

    public function toBankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'to_bank_account_id');
    }
}
