<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * POS_SPECS.md §3H / §4 — Promotion Rules Engine.
 */
class PosPromotionRule extends Model
{
    protected $table = 'POS.pos_promotion_rules';

    public const TYPE_SIMPLE_DISCOUNT = 'simple_discount';
    public const TYPE_BUY_X_GET_Y = 'buy_x_get_y';
    public const TYPE_BUNDLE = 'bundle';
    public const TYPE_MIX_AND_MATCH = 'mix_and_match';
    public const TYPE_THRESHOLD = 'threshold';
    public const TYPE_TIME_WINDOW = 'time_window';
    public const TYPE_CUSTOMER_TIER = 'customer_tier';
    public const TYPE_PROMO_CODE_PASSTHROUGH = 'promo_code_passthrough';

    public const SCOPE_PRODUCT = 'product';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_BASKET = 'basket';

    public const VALUE_TYPE_PERCENT = 'percent';
    public const VALUE_TYPE_FIXED = 'fixed';
    public const VALUE_TYPE_BUNDLE_PRICE = 'bundle_price';

    protected $fillable = [
        'name',
        'type',
        'scope',
        'value_type',
        'value',
        'constraints',
        'valid_from',
        'valid_to',
        'priority',
        'stackable',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'constraints' => 'array',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'priority' => 'integer',
        'stackable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('valid_to')->orWhere('valid_to', '>=', now());
            });
    }
}
