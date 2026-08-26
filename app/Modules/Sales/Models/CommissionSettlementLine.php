<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionSettlementLine extends Model
{
    protected $table = 'SALES.comm_settlement_lines';

    public const TYPE_EARNED = 'earned';
    public const TYPE_REVERSAL = 'reversal';

    protected $fillable = [
        'settlement_id',
        'commission_plan_id',
        'so_line_id',
        'line_type',
        'amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function settlement()
    {
        return $this->belongsTo(CommissionSettlement::class, 'settlement_id');
    }

    public function commissionPlan()
    {
        return $this->belongsTo(CommissionPlan::class, 'commission_plan_id');
    }

    public function salesOrderLine()
    {
        return $this->belongsTo(SalesOrderLine::class, 'so_line_id');
    }
}
