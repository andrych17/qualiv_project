<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;

class PurInvoiceLine extends Model
{
    public $timestamps = false;

    protected $table = 'PURCHASE.pur_invoice_lines';

    protected $fillable = [
        'invoice_id',
        'po_line_id',
        'qty',
        'unit_price',
        'line_amount',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'line_amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(PurInvoiceHdr::class, 'invoice_id');
    }

    public function poLine()
    {
        return $this->belongsTo(PurOrderLine::class, 'po_line_id');
    }
}
