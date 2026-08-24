<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3J — one account × cost center × period budget amount; see the migration docblock for why there's no composite unique constraint. */
class BudgetLine extends Model
{
    protected $table = 'ACCOUNTING.budget_lines';

    public $timestamps = false;

    protected $fillable = ['budget_id', 'account_id', 'cost_center_id', 'fiscal_period_id', 'amount'];

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter()
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function fiscalPeriod()
    {
        return $this->belongsTo(FiscalPeriod::class);
    }
}
