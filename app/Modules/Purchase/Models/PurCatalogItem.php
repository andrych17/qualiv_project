<?php

namespace App\Modules\Purchase\Models;

use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

class PurCatalogItem extends Model
{
    protected $table = 'PURCHASE.pur_catalog_items';

    protected $fillable = [
        'item_code',
        'description',
        'category_id',
        'unit',
        'preferred_supplier_id',
        'negotiated_price',
        'price_valid_from',
        'price_valid_to',
        'source',
        'is_active',
    ];

    protected $casts = [
        'negotiated_price' => 'decimal:2',
        'price_valid_from' => 'date',
        'price_valid_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function preferredSupplier()
    {
        return $this->belongsTo(Partner::class, 'preferred_supplier_id');
    }

    public function requisitionLines()
    {
        return $this->hasMany(PurRequisitionLine::class, 'catalog_item_id');
    }

    public function orderLines()
    {
        return $this->hasMany(PurOrderLine::class, 'catalog_item_id');
    }
}
