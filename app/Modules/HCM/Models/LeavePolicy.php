<?php

namespace App\Modules\HCM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeavePolicy extends Model
{
    protected $table = 'HCM.leave_policies';

    public $timestamps = false;

    protected $fillable = [
        'leave_type_id',
        'contract_type',
        'entitlement_days_per_year',
        'accrual_method',
        'carry_over_max_days',
        'carry_over_expiry_months',
        'is_paid',
    ];

    protected $casts = [
        'entitlement_days_per_year' => 'decimal:2',
        'carry_over_max_days' => 'decimal:2',
        'carry_over_expiry_months' => 'integer',
        'is_paid' => 'boolean',
    ];

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
