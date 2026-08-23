<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3B fiscal calendar — a company's fiscal year, auto-generates 12 monthly periods on creation. */
class FiscalYear extends Model
{
    protected $table = 'ACCOUNTING.fiscal_years';

    public $timestamps = false;

    public const STATUS_OPEN = 'open';

    public const STATUS_SOFT_CLOSED = 'soft_closed';

    public const STATUS_HARD_CLOSED = 'hard_closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_SOFT_CLOSED, self::STATUS_HARD_CLOSED];

    protected $fillable = ['company_id', 'year', 'start_date', 'end_date', 'status'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function periods()
    {
        return $this->hasMany(FiscalPeriod::class);
    }
}
