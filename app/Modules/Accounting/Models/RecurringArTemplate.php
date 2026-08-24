<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

/** §3P — a recurring AR invoice's header + line pattern (e.g. a monthly retainer); RecurringGenerationService drafts an ArInvoice from it on each due occurrence. */
class RecurringArTemplate extends Model
{
    protected $table = 'ACCOUNTING.recurring_ar_templates';

    protected $fillable = [
        'uuid', 'company_id', 'partner_id', 'name', 'currency_code', 'invoice_type', 'payment_terms_days',
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

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function lines()
    {
        return $this->hasMany(RecurringArTemplateLine::class)->orderBy('line_no');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
