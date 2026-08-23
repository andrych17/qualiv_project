<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

/** §3E — a vendor bill. Mirrors ArInvoice structurally; see the §3E migration docblock for the deliberate deviations (bill_no, vendor_faktur_no, withheld_amount). */
class ApBill extends Model
{
    protected $table = 'ACCOUNTING.ap_bills';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_POSTED, self::STATUS_PARTIALLY_PAID, self::STATUS_PAID, self::STATUS_VOID,
    ];

    protected $fillable = [
        'uuid', 'company_id', 'partner_id', 'bill_no', 'currency_code', 'issue_date', 'due_date',
        'vendor_faktur_no', 'withholding_type_id', 'subject_type', 'subject_id', 'status',
        'subtotal', 'tax_amount', 'withheld_amount', 'total_amount', 'paid_amount', 'debited_amount',
        'journal_id', 'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'withheld_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'debited_amount' => 'decimal:2',
    ];

    /**
     * §3E: the actual liability owed to the vendor is `total_amount` (gross expense)
     * MINUS `withheld_amount` — that portion was never going to be paid to the vendor,
     * it's remitted to the tax office instead (§3E rule: withholding "does not reduce
     * the gross expense recognized"). The single formula behind status, payments,
     * debit notes, and AP aging.
     */
    public function openBalance(): float
    {
        return round((float) $this->total_amount - (float) $this->withheld_amount - (float) $this->paid_amount - (float) $this->debited_amount, 2);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function withholdingType()
    {
        return $this->belongsTo(WithholdingType::class);
    }

    public function lines()
    {
        return $this->hasMany(ApBillLine::class)->orderBy('line_no');
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class);
    }

    public function paymentApplications()
    {
        return $this->hasMany(ApPaymentApplication::class);
    }

    public function debitNotes()
    {
        return $this->hasMany(ApDebitNote::class);
    }

    /** No DB FK — tax_faktur_pajak.ap_bill_id is a plain column (§3M migration docblock). */
    public function inputFakturPajak()
    {
        return $this->hasOne(TaxFakturPajak::class, 'ap_bill_id');
    }

    /** No DB FK — tax_bukti_potong.ap_bill_id is a plain column (§3M migration docblock). */
    public function buktiPotong()
    {
        return $this->hasOne(TaxBuktiPotong::class, 'ap_bill_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
