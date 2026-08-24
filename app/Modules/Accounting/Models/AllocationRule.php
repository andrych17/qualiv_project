<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3I — a percentage-split cost allocation rule (e.g. "office rent → case teams"); AllocationRunService is what actually posts a journal from it. */
class AllocationRule extends Model
{
    protected $table = 'ACCOUNTING.allocation_rules';

    protected $fillable = ['uuid', 'company_id', 'name', 'source_account_id', 'source_cost_center_id', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function sourceAccount()
    {
        return $this->belongsTo(Account::class, 'source_account_id');
    }

    public function sourceCostCenter()
    {
        return $this->belongsTo(CostCenter::class, 'source_cost_center_id');
    }

    public function targets()
    {
        return $this->hasMany(AllocationRuleTarget::class);
    }

    public function runs()
    {
        return $this->hasMany(AllocationRun::class);
    }
}
