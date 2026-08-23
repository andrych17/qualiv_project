<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

/** §3E — a vendor disbursement, applied against one or more open bills. */
class ApPayment extends Model
{
    protected $table = 'ACCOUNTING.ap_payments';

    public const STATUS_DRAFT = 'draft';

    // Reserved, unreachable — §3E's payment-approval-above-threshold reuses §3C's WNE
    // approval seam, which JournalService itself hasn't wired yet (no published workflow
    // definition). See the §3E migration docblock; same treatment as GlJournal's own
    // STATUS_PENDING_APPROVAL.
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOID = 'void';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL, self::STATUS_POSTED, self::STATUS_VOID];

    protected $fillable = [
        'uuid', 'company_id', 'partner_id', 'cash_gl_account_id', 'currency_code',
        'payment_date', 'amount', 'memo', 'status', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_gl_account_id');
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class);
    }

    public function applications()
    {
        return $this->hasMany(ApPaymentApplication::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
