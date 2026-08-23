<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3D — one billed line item on a customer invoice. */
class ArInvoiceLine extends Model
{
    protected $table = 'ACCOUNTING.ar_invoice_lines';

    public $timestamps = false;

    protected $fillable = [
        'ar_invoice_id', 'line_no', 'description', 'qty', 'unit_price', 'discount_amount',
        'tax_code_id', 'revenue_account_id', 'line_amount', 'tax_amount',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }

    public function taxCode()
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function revenueAccount()
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }
}
