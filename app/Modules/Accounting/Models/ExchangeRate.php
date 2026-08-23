<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3L — a company's rate-to-base for a currency, effective on a given date. */
class ExchangeRate extends Model
{
    protected $table = 'ACCOUNTING.exchange_rates';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'company_id', 'currency_code', 'rate_to_base', 'effective_date', 'source',
    ];

    protected $casts = [
        'rate_to_base' => 'decimal:6',
        'effective_date' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }
}
