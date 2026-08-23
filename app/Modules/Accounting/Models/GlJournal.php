<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** §3C — the one posting path every subledger will eventually post through. */
class GlJournal extends Model
{
    protected $table = 'ACCOUNTING.gl_journals';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [
        self::SOURCE_MANUAL, 'ar', 'ap', 'asset', 'inventory', 'payroll', 'recurring', 'allocation',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval'; // reserved — not reachable yet, see JournalService

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL, self::STATUS_POSTED, self::STATUS_REVERSED];

    protected $fillable = [
        'uuid', 'company_id', 'fiscal_period_id', 'journal_date', 'currency_code', 'memo', 'source',
        'status', 'subject_type', 'subject_id', 'reversed_journal_id', 'created_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'journal_date' => 'date',
        'posted_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalPeriod()
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function lines()
    {
        return $this->hasMany(GlJournalLine::class, 'journal_id')->orderBy('line_no');
    }

    public function reversedJournal()
    {
        return $this->belongsTo(self::class, 'reversed_journal_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversed_journal_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
