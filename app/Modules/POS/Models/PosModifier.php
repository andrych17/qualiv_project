<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3N / §4 — Modifier Options.
 */
class PosModifier extends Model
{
    protected $table = 'POS.pos_modifiers';

    public $timestamps = false;

    protected $fillable = [
        'group_id',
        'name',
        'price_delta',
        'replaces_base_price',
    ];

    protected $casts = [
        'price_delta' => 'decimal:2',
        'replaces_base_price' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(PosModifierGroup::class, 'group_id');
    }
}
