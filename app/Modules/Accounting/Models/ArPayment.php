<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

/** §3D — a customer payment, applied against one or more open invoices (§3D). */
class ArPayment extends Model
{
    protected $table = 'ACCOUNTING.ar_payments';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOID = 'void';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_POSTED, self::STATUS_VOID];

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
        return $this->hasMany(ArPaymentApplication::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
