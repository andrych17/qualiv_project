<?php

namespace App\Modules\POS\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS_SPECS.md §3N / §4 — Item Modifier Groups.
 */
class PosModifierGroup extends Model
{
    protected $table = 'POS.pos_modifier_groups';
    public $timestamps = false;

    public const TYPE_SINGLE = 'single';
    public const TYPE_MULTIPLE = 'multiple';

    protected $fillable = [
        'name',
        'selection_type',
        'min_selections',
        'max_selections',
    ];

    protected $casts = [
        'min_selections' => 'integer',
        'max_selections' => 'integer',
    ];

    public function modifiers(): HasMany
    {
        return $this->hasMany(PosModifier::class, 'group_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'POS.pos_product_modifier_groups',
            'group_id',
            'product_id'
        );
    }
}
