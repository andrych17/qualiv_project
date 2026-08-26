<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationLine extends Model
{
    protected $table = 'SALES.quot_lines';

    protected $fillable = [
        'quot_hdr_id',
        'line_no',
        'item_type',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'discount_amount',
        'tax_amount',
        'line_total',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'product_id' => 'integer',
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quot_hdr_id');
    }
}
