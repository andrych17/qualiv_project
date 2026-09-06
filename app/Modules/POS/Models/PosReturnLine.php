<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3L / §4 — POS Return Line Item.
 */
class PosReturnLine extends Model
{
    protected $table = 'POS.pos_return_lines';

    public $timestamps = false;

    protected $fillable = [
        'return_id',
        'original_txn_line_id',
        'qty',
        'unit_price',
        'condition_note',
        'restockable',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'restockable' => 'boolean',
    ];

    public function returnHeader(): BelongsTo
    {
        return $this->belongsTo(PosReturnHdr::class, 'return_id');
    }

    public function originalTxnLine(): BelongsTo
    {
        return $this->belongsTo(PosTxnLine::class, 'original_txn_line_id');
    }
}
