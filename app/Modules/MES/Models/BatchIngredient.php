<?php

namespace App\Modules\MES\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3I — one recipe ingredient, scaled to this batch's `planned_qty` at creation time. */
class BatchIngredient extends Model
{
    protected $table = 'MES.mes_batch_ingredients';

    public $timestamps = false;

    protected $fillable = ['batch_id', 'raw_material_product_id', 'resolved_qty', 'uom_code'];

    protected $casts = [
        'resolved_qty' => 'decimal:6',
    ];

    public function batch()
    {
        return $this->belongsTo(MesBatch::class, 'batch_id');
    }

    public function rawMaterial()
    {
        return $this->belongsTo(Product::class, 'raw_material_product_id');
    }
}
