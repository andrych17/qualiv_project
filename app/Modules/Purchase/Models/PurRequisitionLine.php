<?php

namespace App\Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;

class PurRequisitionLine extends Model
{
    protected $table = 'PURCHASE.pur_requisition_lines';

    protected $fillable = [
        'pr_id',
        'line_no',
        'catalog_item_id',
        'description',
        'qty',
        'estimated_unit_price',
        'category_id',
        'local_content_pct',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'qty' => 'decimal:4',
        'estimated_unit_price' => 'decimal:2',
        'local_content_pct' => 'decimal:2',
    ];

    public function requisition()
    {
        return $this->belongsTo(PurRequisitionHdr::class, 'pr_id');
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
