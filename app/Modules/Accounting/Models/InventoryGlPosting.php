<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3H — one row per successfully-posted Inventory movement; unique(subject_type, subject_id) is the idempotency guard against a replayed/retried event double-posting. */
class InventoryGlPosting extends Model
{
    protected $table = 'ACCOUNTING.inventory_gl_postings';

    public $timestamps = false;

    protected $fillable = ['company_id', 'event_type', 'inventory_item_id', 'subject_type', 'subject_id', 'journal_id'];

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
