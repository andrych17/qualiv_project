<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS_SPECS.md §3R / §4 — Loyalty Tiers.
 */
class PosLoyaltyTier extends Model
{
    protected $table = 'POS.pos_loyalty_tiers';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'points_per_currency_unit',
        'tier_threshold',
        'sort_order',
    ];

    protected $casts = [
        'points_per_currency_unit' => 'decimal:4',
        'tier_threshold' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(PosLoyaltyAccount::class, 'tier_id');
    }
}
