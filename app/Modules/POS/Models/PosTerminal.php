<?php

namespace App\Modules\POS\Models;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * POS_SPECS.md §3B / §4 — POS Terminal / Register.
 */
class PosTerminal extends Model
{
    protected $table = 'POS.pos_terminals';

    protected $fillable = [
        'uuid',
        'branch_id',
        'warehouse_id',
        'profile_id',
        'code',
        'name',
        'default_price_list_id',
        'default_tax_code',
        'receipt_template',
        'receipt_prefix',
        'last_local_seq',
        'device_fingerprint',
        'is_active',
    ];

    protected $casts = [
        'last_local_seq' => 'integer',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(PosBranch::class, 'branch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PosProfile::class, 'profile_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(PosTerminalDevice::class, 'terminal_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PosSession::class, 'terminal_id');
    }

    public function currentSession(): HasOne
    {
        return $this->hasOne(PosSession::class, 'terminal_id')->where('status', 'open');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PosTxnHdr::class, 'terminal_id');
    }

    public function favoriteItems(): HasMany
    {
        return $this->hasMany(PosFavoriteItem::class, 'terminal_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('code', 'ilike', '%'.$search.'%')
                ->orWhere('name', 'ilike', '%'.$search.'%')
                ->orWhere('receipt_prefix', 'ilike', '%'.$search.'%');
        })->when($filters['branch_id'] ?? null, function ($query, $branchId) {
            $query->where('branch_id', $branchId);
        })->when($filters['profile_id'] ?? null, function ($query, $profileId) {
            $query->where('profile_id', $profileId);
        })->when(isset($filters['is_active']), function ($query) use ($filters) {
            $query->where('is_active', (bool) $filters['is_active']);
        });
    }
}
