<?php

namespace App\Modules\Accounting\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

/** §3M Bukti Potong (withholding certificate) — issued on posting a bill/payment subject to PPh withholding. */
class TaxBuktiPotong extends Model
{
    protected $table = 'ACCOUNTING.tax_bukti_potong';

    public $timestamps = false;

    public const TYPES = ['BP21', 'BP26', 'BP23', 'BP4A2', 'BPU'];

    public const STATUS_ISSUED = 'issued';

    public const STATUS_REPLACED = 'replaced';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'company_id', 'bp_type', 'ap_bill_id', 'withholding_type_id', 'partner_id',
        'sequence_no', 'bp_number', 'gross_amount', 'withheld_amount', 'status', 'replaces_bp_id', 'issued_at',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'withheld_amount' => 'decimal:2',
        'issued_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function withholdingType()
    {
        return $this->belongsTo(WithholdingType::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function replacesBuktiPotong()
    {
        return $this->belongsTo(self::class, 'replaces_bp_id');
    }
}
