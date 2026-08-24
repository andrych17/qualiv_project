<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class AllocationRuleTarget extends Model
{
    protected $table = 'ACCOUNTING.allocation_rule_targets';

    public $timestamps = false;

    protected $fillable = ['allocation_rule_id', 'cost_center_id', 'percentage'];

    protected $casts = ['percentage' => 'decimal:2'];

    public function rule()
    {
        return $this->belongsTo(AllocationRule::class, 'allocation_rule_id');
    }

    public function costCenter()
    {
        return $this->belongsTo(CostCenter::class);
    }
}
