<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3B Chart of Accounts. */
class Account extends Model
{
    protected $table = 'ACCOUNTING.accounts';

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_COGS = 'cogs';

    public const TYPE_EXPENSE = 'expense';

    public const TYPES = [
        self::TYPE_ASSET, self::TYPE_LIABILITY, self::TYPE_EQUITY,
        self::TYPE_REVENUE, self::TYPE_COGS, self::TYPE_EXPENSE,
    ];

    public const BALANCE_DEBIT = 'debit';

    public const BALANCE_CREDIT = 'credit';

    public const BALANCES = [self::BALANCE_DEBIT, self::BALANCE_CREDIT];

    protected $fillable = [
        'company_id', 'account_code', 'account_name', 'account_type', 'normal_balance',
        'parent_account_id', 'is_control_account', 'is_active',
    ];

    protected $casts = [
        'is_control_account' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    public function glJournalLines()
    {
        return $this->hasMany(GlJournalLine::class, 'account_id');
    }
}
