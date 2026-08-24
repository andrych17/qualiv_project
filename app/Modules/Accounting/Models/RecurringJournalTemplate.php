<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** §3P — a recurring journal's header + line pattern; RecurringGenerationService drafts a GlJournal from it on each due occurrence. */
class RecurringJournalTemplate extends Model
{
    protected $table = 'ACCOUNTING.recurring_journal_templates';

    protected $fillable = [
        'uuid', 'company_id', 'name', 'memo', 'currency_code',
        'recurrence_rule', 'anchor_date', 'next_run_date', 'last_run_date', 'is_active', 'created_by',
    ];

    protected $casts = [
        'anchor_date' => 'date',
        'next_run_date' => 'date',
        'last_run_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function lines()
    {
        return $this->hasMany(RecurringJournalTemplateLine::class)->orderBy('line_no');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
