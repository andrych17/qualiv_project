<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;

class PurReceiptLine extends Model
{
    protected $table = 'PURCHASE.pur_receipt_lines';

    protected $fillable = [
        'gr_id',
        'po_line_id',
        'quantity_received',
        'unit_cost',
        'condition_notes',
        'over_receipt_flag',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:4',
        'unit_cost' => 'decimal:2',
        'over_receipt_flag' => 'boolean',
    ];

    public function receipt()
    {
        return $this->belongsTo(PurReceiptHdr::class, 'gr_id');
    }

    public function poLine()
    {
        return $this->belongsTo(PurOrderLine::class, 'po_line_id');
    }
}
