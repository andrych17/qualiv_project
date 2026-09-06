<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS_SPECS.md §3A / §4 — POS Profile & Capability Matrix.
 */
class PosProfile extends Model
{
    protected $table = 'POS.pos_profiles';

    public const TYPE_RETAIL = 'retail';

    public const TYPE_RESTAURANT = 'restaurant';

    public const TYPE_SERVICE = 'service';

    protected $fillable = [
        'code',
        'name',
        'base_type',
        'requires_barcode',
        'touch_menu',
        'multi_uom',
        'batch_expiry_tracking',
        'weight_scale',
        'customer_required',
        'loyalty_enabled',
        'promotion_enabled',
        'table_management',
        'modifiers_enabled',
        'kds_enabled',
        'recipe_consumption',
        'delivery_enabled',
        'offline_enabled',
        'multi_branch',
        'is_active',
    ];

    protected $casts = [
        'requires_barcode' => 'boolean',
        'touch_menu' => 'boolean',
        'multi_uom' => 'boolean',
        'batch_expiry_tracking' => 'boolean',
        'weight_scale' => 'boolean',
        'customer_required' => 'boolean',
        'loyalty_enabled' => 'boolean',
        'promotion_enabled' => 'boolean',
        'table_management' => 'boolean',
        'modifiers_enabled' => 'boolean',
        'kds_enabled' => 'boolean',
        'recipe_consumption' => 'boolean',
        'delivery_enabled' => 'boolean',
        'offline_enabled' => 'boolean',
        'multi_branch' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function terminals(): HasMany
    {
        return $this->hasMany(PosTerminal::class, 'profile_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('code', 'ilike', '%'.$search.'%')
                ->orWhere('name', 'ilike', '%'.$search.'%');
        })->when($filters['base_type'] ?? null, function ($query, $type) {
            $query->where('base_type', $type);
        })->when(isset($filters['is_active']), function ($query) use ($filters) {
            $query->where('is_active', (bool) $filters['is_active']);
        });
    }
}
