<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunLineDetail extends Model
{
    protected $table = 'PAYROLL.payroll_run_line_details';

    protected $fillable = [
        'payroll_run_line_id',
        'payroll_component_id',
        'component_name',
        'type',
        'category',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function runLine(): BelongsTo
    {
        return $this->belongsTo(PayrollRunLine::class, 'payroll_run_line_id');
    }

    public function payrollComponent(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }
}
