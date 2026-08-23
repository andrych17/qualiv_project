<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/** §3E — one billed line item on a vendor bill. */
class ApBillLine extends Model
{
    protected $table = 'ACCOUNTING.ap_bill_lines';

    public $timestamps = false;

    protected $fillable = [
        'ap_bill_id', 'line_no', 'description', 'qty', 'unit_price', 'discount_amount',
        'tax_code_id', 'expense_account_id', 'line_amount', 'tax_amount',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    public function bill()
    {
        return $this->belongsTo(ApBill::class, 'ap_bill_id');
    }

    public function taxCode()
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function expenseAccount()
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }
}
