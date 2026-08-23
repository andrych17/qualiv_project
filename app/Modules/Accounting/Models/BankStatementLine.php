<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3F — one staged bank statement row. amount is signed: positive = inflow, negative = outflow. */
class BankStatementLine extends Model
{
    protected $table = 'ACCOUNTING.bank_statement_lines';

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_IGNORED = 'ignored';

    public $timestamps = false;

    protected $fillable = [
        'import_id', 'line_date', 'description', 'amount', 'reference', 'status', 'created_at',
        'matched_journal_line_id', 'matched_at', 'matched_by',
    ];

    protected $casts = [
        'line_date' => 'date',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'matched_at' => 'datetime',
    ];

    public function import()
    {
        return $this->belongsTo(BankStatementImport::class, 'import_id');
    }

    /** §3Q — the posted gl_journal_lines row this statement line was reconciled against. */
    public function matchedJournalLine()
    {
        return $this->belongsTo(GlJournalLine::class, 'matched_journal_line_id');
    }
}
