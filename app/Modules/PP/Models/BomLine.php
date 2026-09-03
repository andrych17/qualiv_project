<?php

namespace App\Modules\PP\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;

class BomLine extends Model
{
    protected $table = 'PP.pp_bom_lines';

    public $timestamps = false;

    protected $fillable = ['bom_id', 'component_product_id', 'qty_per_parent_unit', 'uom_code', 'scrap_pct'];

    protected $casts = [
        'qty_per_parent_unit' => 'decimal:6',
        'scrap_pct' => 'decimal:2',
    ];

    public function bom()
    {
        return $this->belongsTo(Bom::class, 'bom_id');
    }

    public function component()
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
