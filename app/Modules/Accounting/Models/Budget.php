<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3J — one flat annual budget per company/fiscal year; BudgetService::saveGrid() is what writes its lines. */
class Budget extends Model
{
    protected $table = 'ACCOUNTING.budgets';

    protected $fillable = ['uuid', 'company_id', 'fiscal_year_id', 'created_by'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function lines()
    {
        return $this->hasMany(BudgetLine::class);
    }
}
