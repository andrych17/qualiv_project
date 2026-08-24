<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3S — one row per successfully-posted Payroll run; unique(subject_type, subject_id) is the idempotency guard against a replayed/retried event double-posting. */
class PayrollGlPosting extends Model
{
    protected $table = 'ACCOUNTING.payroll_gl_postings';

    public $timestamps = false;

    protected $fillable = ['company_id', 'subject_type', 'subject_id', 'journal_id'];

    protected $casts = ['created_at' => 'datetime'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class, 'journal_id');
    }
}
