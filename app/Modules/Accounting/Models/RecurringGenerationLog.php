<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3P — one row per occurrence actually generated; the unique(template_type, template_id, run_date) constraint is the idempotency guard against a double-fired sweep. */
class RecurringGenerationLog extends Model
{
    protected $table = 'ACCOUNTING.recurring_generation_log';

    public $timestamps = false;

    public const TYPE_JOURNAL = 'journal';

    public const TYPE_AR_INVOICE = 'ar_invoice';

    protected $fillable = ['template_type', 'template_id', 'run_date', 'generated_subject_type', 'generated_subject_id', 'created_at'];

    protected $casts = [
        'run_date' => 'date',
        'created_at' => 'datetime',
    ];
}
