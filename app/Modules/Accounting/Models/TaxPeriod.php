<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §3M tax period register (masa pajak). Persisted `filing_status` is only 'open'|'filed' —
 * 'late' is never written; it's derived display state via isLate(), computed from due_date,
 * same "reserved-but-computed" treatment as gl_journals.pending_approval is reserved-but-
 * unreachable. Keeping it out of the stored column avoids a background job whose only job
 * is flipping a status no rule depends on.
 */
class TaxPeriod extends Model
{
    protected $table = 'ACCOUNTING.tax_periods';

    public $timestamps = false;

    public const OBLIGATION_PPN = 'ppn';

    public const OBLIGATION_PPH = 'pph';

    public const OBLIGATIONS = [self::OBLIGATION_PPN, self::OBLIGATION_PPH];

    public const STATUS_OPEN = 'open';

    public const STATUS_FILED = 'filed';

    protected $fillable = ['company_id', 'obligation_type', 'masa_pajak', 'due_date', 'filing_status', 'filed_at'];

    protected $casts = [
        'due_date' => 'date',
        'filed_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function isLate(): bool
    {
        return $this->filing_status === self::STATUS_OPEN && $this->due_date->isPast();
    }
}
