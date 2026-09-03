<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3N / §4 — Snapshot of Selected Modifier on a Transaction Line.
 */
class PosTxnLineModifier extends Model
{
    protected $table = 'POS.pos_txn_line_modifiers';
    public $timestamps = false;

    protected $fillable = [
        'txn_line_id',
        'modifier_id',
        'modifier_name',
        'price_delta',
    ];

    protected $casts = [
        'price_delta' => 'decimal:2',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(PosTxnLine::class, 'txn_line_id');
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(PosModifier::class, 'modifier_id');
    }
}
