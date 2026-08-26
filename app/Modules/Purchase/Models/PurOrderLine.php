<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;

class PurOrderLine extends Model
{
    protected $table = 'PURCHASE.pur_order_lines';

    protected $fillable = [
        'po_id',
        'line_no',
        'catalog_item_id',
        'description',
        'qty_ordered',
        'qty_received',
        'unit_price',
        'tax_amount',
        'expected_delivery_date',
        'category_id',
        'local_content_pct',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'qty_ordered' => 'decimal:4',
        'qty_received' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'expected_delivery_date' => 'date',
        'local_content_pct' => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(PurOrderHdr::class, 'po_id');
    }

    public function catalogItem()
    {
        return $this->belongsTo(PurCatalogItem::class, 'catalog_item_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
