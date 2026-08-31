<?php

namespace App\Modules\PP\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;

class RecipeIngredient extends Model
{
    protected $table = 'PP.pp_recipe_ingredients';

    public $timestamps = false;

    protected $fillable = ['recipe_id', 'raw_material_product_id', 'qty_per_batch', 'uom_code'];

    protected $casts = [
        'qty_per_batch' => 'decimal:6',
    ];

    public function recipe()
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }

    public function rawMaterial()
    {
        return $this->belongsTo(Product::class, 'raw_material_product_id');
    }
}
