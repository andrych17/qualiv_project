<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

/** §3D — a customer invoice. Accounting is the platform's only AR ledger (§3D rule). */
class ArInvoice extends Model
{
    protected $table = 'ACCOUNTING.ar_invoices';

    public const TYPE_STANDARD = 'standard';

    public const TYPE_DEPOSIT = 'deposit';

    public const TYPES = [self::TYPE_STANDARD, self::TYPE_DEPOSIT];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_POSTED, self::STATUS_PARTIALLY_PAID, self::STATUS_PAID, self::STATUS_VOID,
    ];

    protected $fillable = [
        'uuid', 'company_id', 'partner_id', 'invoice_no', 'invoice_type', 'currency_code', 'fx_rate',
        'issue_date', 'due_date', 'subject_type', 'subject_id', 'status',
        'subtotal', 'tax_amount', 'total_amount', 'paid_amount', 'credited_amount',
        'journal_id', 'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'fx_rate' => 'decimal:6',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'credited_amount' => 'decimal:2',
    ];

    /** §3D: the single formula behind status, aging, and the AR-control-account guarantee. */
    public function openBalance(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount - (float) $this->credited_amount, 2);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function lines()
    {
        return $this->hasMany(ArInvoiceLine::class)->orderBy('line_no');
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class);
    }

    public function paymentApplications()
    {
        return $this->hasMany(ArPaymentApplication::class);
    }

    public function creditNotes()
    {
        return $this->hasMany(ArCreditNote::class);
    }

    /** No DB FK — tax_faktur_pajak.ar_invoice_id is a plain column (see §3M migration docblock). */
    public function fakturPajak()
    {
        return $this->hasOne(TaxFakturPajak::class, 'ar_invoice_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
