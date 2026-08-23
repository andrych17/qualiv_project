<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3C — a balanced journal's individual debit/credit line. */
class GlJournalLine extends Model
{
    protected $table = 'ACCOUNTING.gl_journal_lines';

    public $timestamps = false;

    protected $fillable = [
        'journal_id', 'line_no', 'account_id', 'cost_center_id', 'debit', 'credit',
        'fx_currency_code', 'fx_amount', 'fx_rate', 'description',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function journal()
    {
        return $this->belongsTo(GlJournal::class, 'journal_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter()
    {
        return $this->belongsTo(CostCenter::class);
    }
}
