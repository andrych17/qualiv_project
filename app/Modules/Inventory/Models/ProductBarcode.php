<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBarcode extends Model
{
    protected $table = 'INVENTORY.product_barcodes';

    public $timestamps = false;

    public const TYPE_PRIMARY = 'primary';

    public const TYPE_CASE_PACK = 'case_pack';

    public const TYPE_ALTERNATE = 'alternate';

    protected $fillable = ['product_id', 'barcode', 'type', 'unit_multiplier'];

    protected $casts = [
        'unit_multiplier' => 'decimal:6',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
