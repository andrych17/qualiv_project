<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;

class PurInvoiceMatch extends Model
{
    protected $table = 'PURCHASE.pur_invoice_matches';

    protected $fillable = [
        'invoice_id',
        'po_line_id',
        'po_qty',
        'po_price',
        'gr_qty',
        'invoice_qty',
        'invoice_price',
        'qty_variance_pct',
        'price_variance_pct',
        'within_tolerance',
    ];

    protected $casts = [
        'po_qty' => 'decimal:4',
        'po_price' => 'decimal:2',
        'gr_qty' => 'decimal:4',
        'invoice_qty' => 'decimal:4',
        'invoice_price' => 'decimal:2',
        'qty_variance_pct' => 'decimal:2',
        'price_variance_pct' => 'decimal:2',
        'within_tolerance' => 'boolean',
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
