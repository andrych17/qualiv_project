<?php

namespace App\Modules\Performance\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * §3B — tenant-editable mapping from a budget line's free-text `category` to one or more
 * `ACCOUNTING.accounts`, optionally scoped to a company. Existence of an active mapping for a
 * category is what makes that category's budget lines GL-sourced instead of manual — see
 * VarianceService::evaluateBudgetLine().
 */
class BudgetCategoryAccount extends Model
{
    protected $table = 'PERF.budget_category_accounts';

    protected $fillable = ['category', 'account_id', 'company_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
