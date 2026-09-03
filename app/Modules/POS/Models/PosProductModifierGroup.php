<?php

namespace App\Modules\POS\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3N / §4 — Product to Modifier Group Attachment.
 */
class PosProductModifierGroup extends Model
{
    protected $table = 'POS.pos_product_modifier_groups';
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'group_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(PosModifierGroup::class, 'group_id');
    }
}
