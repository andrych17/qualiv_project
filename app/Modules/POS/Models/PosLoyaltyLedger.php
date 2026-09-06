<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3R / §4 — Append-only Loyalty Ledger.
 */
class PosLoyaltyLedger extends Model
{
    protected $table = 'POS.pos_loyalty_ledger';

    public $timestamps = false;

    public const TYPE_EARN = 'earn';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_EXPIRE = 'expire';

    public const TYPE_ADJUST = 'adjust';

    protected $fillable = [
        'account_id',
        'txn_id',
        'type',
        'points_delta',
        'occurred_at',
    ];

    protected $casts = [
        'points_delta' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(PosLoyaltyAccount::class, 'account_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PosTxnHdr::class, 'txn_id');
    }
}
