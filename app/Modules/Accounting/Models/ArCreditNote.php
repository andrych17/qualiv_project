<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

/**
 * §3D — reduces an invoice or stands alone against a partner's balance. The
 * only representation of a credit adjustment in the schema (`ArInvoice::TYPE_*`
 * deliberately carries no `credit_memo` value, §3D rule).
 */
class ArCreditNote extends Model
{
    protected $table = 'ACCOUNTING.ar_credit_notes';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOID = 'void';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_POSTED, self::STATUS_VOID];

    protected $fillable = [
        'uuid', 'company_id', 'partner_id', 'ar_invoice_id', 'credit_note_no', 'credit_date',
        'amount', 'reason', 'revenue_account_id', 'status', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'credit_date' => 'date',
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

    public function invoice()
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }

    public function revenueAccount()
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
