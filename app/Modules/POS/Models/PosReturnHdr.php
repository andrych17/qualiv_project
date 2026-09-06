<?php

namespace App\Modules\POS\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS_SPECS.md §3L / §4 — POS Return & Refund Header.
 */
class PosReturnHdr extends Model
{
    protected $table = 'POS.pos_return_hdrs';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'original_txn_id',
        'session_id',
        'reason_code',
        'status',
        'refund_method',
        'without_receipt',
        'approved_by',
    ];

    protected $casts = [
        'without_receipt' => 'boolean',
    ];

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(PosTxnHdr::class, 'original_txn_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosReturnLine::class, 'return_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['original_txn_id'] ?? null, function ($query, $txnId) {
            $query->where('original_txn_id', $txnId);
        })->when($filters['session_id'] ?? null, function ($query, $sessionId) {
            $query->where('session_id', $sessionId);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }
}
