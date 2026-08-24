<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3G — one posted commercial depreciation period for one asset. Posts to GL (journal_id); see fiscal counterpart, which never does. */
class DepreciationScheduleCommercial extends Model
{
    protected $table = 'ACCOUNTING.fa_depreciation_schedule_commercial';

    public $timestamps = false;

    protected $fillable = ['asset_id', 'fiscal_period_id', 'depreciation_amount', 'accumulated_depreciation', 'net_book_value', 'journal_id', 'created_at'];

    protected $casts = [
        'depreciation_amount' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'net_book_value' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function fiscalPeriod()
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class, 'journal_id');
    }
}
