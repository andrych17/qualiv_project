<?php

namespace App\Modules\POS\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS_SPECS.md §3R / §4 — Customer Loyalty Account.
 */
class PosLoyaltyAccount extends Model
{
    protected $table = 'POS.pos_loyalty_accounts';

    protected $fillable = [
        'customer_id',
        'tier_id',
        'points_balance',
    ];

    protected $casts = [
        'points_balance' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(PosLoyaltyTier::class, 'tier_id');
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(PosLoyaltyLedger::class, 'account_id');
    }
}
