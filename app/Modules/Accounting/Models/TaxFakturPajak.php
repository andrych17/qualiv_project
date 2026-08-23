<?php

namespace App\Modules\Accounting\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

/** §3M Faktur Pajak (VAT invoice) — issued on posting a taxable AR invoice (output) or recorded from a vendor's own number (input, on an AP bill). */
class TaxFakturPajak extends Model
{
    protected $table = 'ACCOUNTING.tax_faktur_pajak';

    public $timestamps = false;

    public const DIRECTION_OUTPUT = 'output';

    public const DIRECTION_INPUT = 'input';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_REPLACED = 'replaced';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'company_id', 'direction', 'nomor_seri_faktur', 'ar_invoice_id', 'ap_bill_id',
        'partner_id', 'buyer_npwp_nik', 'tax_base', 'ppn_amount', 'status', 'replaces_faktur_id', 'issued_at',
    ];

    protected $casts = [
        'tax_base' => 'decimal:2',
        'ppn_amount' => 'decimal:2',
        'issued_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function replacesFaktur()
    {
        return $this->belongsTo(self::class, 'replaces_faktur_id');
    }
}
