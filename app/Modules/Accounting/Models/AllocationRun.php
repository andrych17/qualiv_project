<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** §3I — one row per period a rule was actually run for; unique(allocation_rule_id, fiscal_period_id) is the idempotency guard against running the same rule twice for the same period. */
class AllocationRun extends Model
{
    protected $table = 'ACCOUNTING.allocation_runs';

    public $timestamps = false;

    protected $fillable = ['allocation_rule_id', 'fiscal_period_id', 'source_amount', 'journal_id', 'created_by', 'created_at'];

    protected $casts = [
        'source_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function rule()
    {
        return $this->belongsTo(AllocationRule::class, 'allocation_rule_id');
    }

    public function fiscalPeriod()
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
