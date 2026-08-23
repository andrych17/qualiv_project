<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3B fiscal calendar — the posting gate JournalService checks on every post/reverse (§3C). */
class FiscalPeriod extends Model
{
    protected $table = 'ACCOUNTING.fiscal_periods';

    public $timestamps = false;

    public const STATUS_OPEN = 'open';

    public const STATUS_SOFT_CLOSED = 'soft_closed';

    public const STATUS_HARD_CLOSED = 'hard_closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_SOFT_CLOSED, self::STATUS_HARD_CLOSED];

    protected $fillable = ['company_id', 'fiscal_year_id', 'period_no', 'start_date', 'end_date', 'status'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
