<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * §3H — "fails loudly and queues for review rather than posting to a suspense account
 * silently" (spec rule). One row per movement event that couldn't post — no GL mapping, an
 * incomplete mapping (missing the specific account the movement type needs), or no fiscal
 * period covering the movement date. `payload` carries the original event's data so a Retry
 * (InventoryGlPostingService::retry()) can replay it once the underlying problem is fixed.
 */
class InventoryPostingFailure extends Model
{
    protected $table = 'ACCOUNTING.inventory_posting_failures';

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'uuid', 'company_id', 'event_type', 'inventory_item_id', 'subject_type', 'subject_id',
        'payload', 'reason', 'status', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
