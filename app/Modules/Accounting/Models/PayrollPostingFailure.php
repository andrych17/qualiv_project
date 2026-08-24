<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * §3S — "fails loudly and queues for review rather than posting to a suspense account
 * silently" (spec rule). One row per payroll run that couldn't post — a component with no
 * mapping, an employer_cost mapping missing its payable_account_id, no Net Pay Payable
 * control account configured, or no fiscal period covering the run date. `payload` carries
 * the original event's data so a Retry (PayrollGlPostingService::retry()) can replay it.
 */
class PayrollPostingFailure extends Model
{
    protected $table = 'ACCOUNTING.payroll_posting_failures';

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'uuid', 'company_id', 'subject_type', 'subject_id',
        'payload', 'reason', 'status', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
