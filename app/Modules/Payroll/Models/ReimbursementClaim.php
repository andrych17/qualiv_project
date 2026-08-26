<?php

namespace App\Modules\Payroll\Models;

use App\Models\User;
use App\Modules\HCM\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReimbursementClaim extends Model
{
    protected $table = 'PAYROLL.reimbursement_claims';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'employee_id',
        'reimbursement_category_id',
        'claim_date',
        'amount',
        'description',
        'receipt_attachment_url',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'claim_date' => 'date',
        'amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReimbursementCategory::class, 'reimbursement_category_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
