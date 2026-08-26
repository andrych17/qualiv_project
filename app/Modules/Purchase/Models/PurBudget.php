<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;

class PurBudget extends Model
{
    protected $table = 'PURCHASE.pur_budgets';

    protected $fillable = [
        'period',
        'cost_center_id',
        'category_id',
        'budget_amount',
        'committed_amount',
        'actual_amount',
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
        'committed_amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
    ];

    public function costCenter()
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
