<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringArTemplateLine extends Model
{
    protected $table = 'ACCOUNTING.recurring_ar_template_lines';

    public $timestamps = false;

    protected $fillable = ['recurring_ar_template_id', 'line_no', 'description', 'qty', 'unit_price', 'discount_amount', 'tax_code_id', 'revenue_account_id'];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
    ];

    public function template()
    {
        return $this->belongsTo(RecurringArTemplate::class, 'recurring_ar_template_id');
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
