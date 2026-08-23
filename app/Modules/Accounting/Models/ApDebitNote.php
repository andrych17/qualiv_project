<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

/** §3E — mirrors ArCreditNote: reduces a bill or stands alone against a partner's balance. */
class ApDebitNote extends Model
{
    protected $table = 'ACCOUNTING.ap_debit_notes';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOID = 'void';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_POSTED, self::STATUS_VOID];

    protected $fillable = [
        'uuid', 'company_id', 'partner_id', 'ap_bill_id', 'debit_note_no', 'debit_date',
        'amount', 'reason', 'expense_account_id', 'status', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'debit_date' => 'date',
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

    public function bill()
    {
        return $this->belongsTo(ApBill::class, 'ap_bill_id');
    }

    public function expenseAccount()
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
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
