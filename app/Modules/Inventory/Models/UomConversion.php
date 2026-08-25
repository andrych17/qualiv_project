<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class UomConversion extends Model
{
    protected $table = 'INVENTORY.uom_conversions';

    public $timestamps = false;

    protected $fillable = ['product_id', 'uom_id', 'conversion_factor'];

    protected $casts = [
        'conversion_factor' => 'decimal:6',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class);
    }
}
