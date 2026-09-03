<?php

namespace App\Modules\POS\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3E / §4 — Favorite / Quick-Pick Items for Cashier.
 */
class PosFavoriteItem extends Model
{
    protected $table = 'POS.pos_favorite_items';
    public $timestamps = false;

    protected $fillable = [
        'terminal_id',
        'cashier_user_id',
        'product_id',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
