<?php

namespace App\Modules\Legal\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

class DeedTax extends Model
{
    protected $table = 'LEGAL.deed_taxes';

    public const TYPE_PPH_FINAL = 'pph_final';

    public const TYPE_BPHTB = 'bphtb';

    public const TYPES = [self::TYPE_PPH_FINAL, self::TYPE_BPHTB];

    public const STATUS_PENDING = 'pending';

    public const STATUS_BILLING_CODE_ISSUED = 'billing_code_issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_VALIDATED = 'validated';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_BILLING_CODE_ISSUED, self::STATUS_PAID, self::STATUS_VALIDATED];

    protected $fillable = [
        'deed_id', 'tax_type', 'taxpayer_partner_id', 'base_amount', 'njop_amount',
        'rate', 'npoptkp_applied', 'computed_amount', 'billing_code', 'ntpn', 'status',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'njop_amount' => 'decimal:2',
        'rate' => 'decimal:2',
        'npoptkp_applied' => 'decimal:2',
        'computed_amount' => 'decimal:2',
    ];

    public function deed()
    {
        return $this->belongsTo(Deed::class);
    }

    public function taxpayer()
    {
        return $this->belongsTo(Partner::class, 'taxpayer_partner_id');
    }
}
