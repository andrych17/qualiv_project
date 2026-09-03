<?php

namespace App\Modules\POS\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS_SPECS.md §3C, §3D / §4 — POS Session (Cash Shift).
 */
class PosSession extends Model
{
    protected $table = 'POS.pos_sessions';
    public $timestamps = false;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $appends = ['session_no'];

    public function getSessionNoAttribute(): string
    {
        return 'SES-' . str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    protected $fillable = [
        'terminal_id',
        'cashier_user_id',
        'cashier_employee_id',
        'opened_at',
        'opening_cash',
        'status',
        'closed_at',
        'expected_cash',
        'actual_cash',
        'variance',
        'closed_by',
        'approved_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'variance' => 'decimal:2',
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class, 'session_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PosTxnHdr::class, 'session_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PosReturnHdr::class, 'session_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['terminal_id'] ?? null, function ($query, $terminalId) {
            $query->where('terminal_id', $terminalId);
        })->when($filters['cashier_user_id'] ?? null, function ($query, $userId) {
            $query->where('cashier_user_id', $userId);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }
}
