<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class PriceListLine extends Model
{
    protected $table = 'SALES.price_list_lines';

    protected $fillable = [
        'price_list_id',
        'item_type',
        'product_id',
        'description',
        'unit_price',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'product_id' => 'integer',
    ];

    public function priceList()
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }
}
