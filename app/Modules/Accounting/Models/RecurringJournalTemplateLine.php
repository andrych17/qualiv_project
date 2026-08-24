<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringJournalTemplateLine extends Model
{
    protected $table = 'ACCOUNTING.recurring_journal_template_lines';

    public $timestamps = false;

    protected $fillable = ['recurring_journal_template_id', 'line_no', 'account_id', 'cost_center_id', 'debit', 'credit', 'description'];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function template()
    {
        return $this->belongsTo(RecurringJournalTemplate::class, 'recurring_journal_template_id');
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
