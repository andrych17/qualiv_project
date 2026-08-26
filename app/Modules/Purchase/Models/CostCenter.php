<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    protected $table = 'PURCHASE.cost_centers';

    protected $fillable = [
        'code',
        'name',
        'accounting_cost_center_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function requisitions()
    {
        return $this->hasMany(PurRequisitionHdr::class, 'cost_center_id');
    }

    public function budgets()
    {
        return $this->hasMany(PurBudget::class, 'cost_center_id');
    }
}
