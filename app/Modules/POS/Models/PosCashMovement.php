<?php

namespace App\Modules\POS\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3C, §3D / §4 — Append-only Cash Movements (Float, Cash Drop, Petty Cash).
 */
class PosCashMovement extends Model
{
    protected $table = 'POS.pos_cash_movements';
    public $timestamps = false;

    public const TYPE_CASH_IN = 'cash_in';
    public const TYPE_CASH_OUT = 'cash_out';
    public const TYPE_PETTY_CASH = 'petty_cash';

    protected $fillable = [
        'session_id',
        'type',
        'amount',
        'reason',
        'user_id',
        'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
