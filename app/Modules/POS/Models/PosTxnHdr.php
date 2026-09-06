<?php

namespace App\Modules\POS\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS_SPECS.md §3F, §3I, §3J, §3S / §4 — POS Transaction Header.
 */
class PosTxnHdr extends Model
{
    protected $table = 'POS.pos_txn_hdrs';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PARKED = 'parked';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const DINING_SALE = 'sale';

    public const DINING_DINE_IN = 'dine_in';

    public const DINING_TAKEAWAY = 'takeaway';

    public const DINING_DELIVERY = 'delivery';

    protected $fillable = [
        'uuid',
        'client_txn_uuid',
        'session_id',
        'terminal_id',
        'receipt_number',
        'customer_id',
        'table_id',
        'dining_mode',
        'price_list_id',
        'status',
        'subtotal',
        'discount_total',
        'tax_total',
        'service_charge',
        'rounding',
        'grand_total',
        'is_on_account',
        'sales_order_subject_id',
        'park_label',
        'notes',
        'created_offline',
        'occurred_at',
        'synced_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'rounding' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'is_on_account' => 'boolean',
        'created_offline' => 'boolean',
        'occurred_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(PosTable::class, 'table_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosTxnLine::class, 'txn_id')->orderBy('line_no');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PosPayment::class, 'txn_id');
    }

    public function overrideLogs(): HasMany
    {
        return $this->hasMany(PosOverrideLog::class, 'txn_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PosReturnHdr::class, 'original_txn_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('receipt_number', 'ilike', '%'.$search.'%')
                ->orWhere('park_label', 'ilike', '%'.$search.'%');
        })->when($filters['session_id'] ?? null, function ($query, $sessionId) {
            $query->where('session_id', $sessionId);
        })->when($filters['terminal_id'] ?? null, function ($query, $terminalId) {
            $query->where('terminal_id', $terminalId);
        })->when($filters['customer_id'] ?? null, function ($query, $customerId) {
            $query->where('customer_id', $customerId);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        })->when($filters['dining_mode'] ?? null, function ($query, $mode) {
            $query->where('dining_mode', $mode);
        });
    }
}
