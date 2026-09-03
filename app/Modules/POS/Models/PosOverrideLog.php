<?php

namespace App\Modules\POS\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3T / §4 — Append-only Supervisor In-Transaction Override Audit Log.
 */
class PosOverrideLog extends Model
{
    protected $table = 'POS.pos_override_logs';
    public $timestamps = false;

    public const ACTION_DISCOUNT = 'discount_above_threshold';
    public const ACTION_ITEM_VOID = 'item_void';
    public const ACTION_SALE_VOID = 'sale_void';
    public const ACTION_REFUND = 'refund';
    public const ACTION_PRICE_OVERRIDE = 'price_override';
    public const ACTION_DRAWER_OPEN = 'drawer_open';
    public const ACTION_SESSION_REOPEN = 'session_reopen';

    protected $fillable = [
        'txn_id',
        'session_id',
        'action_type',
        'requested_by',
        'authorized_by',
        'reason',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PosTxnHdr::class, 'txn_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function authorizedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
