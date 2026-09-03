<?php

namespace App\Modules\POS\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3O / §4 — Product to KDS Station Routing.
 */
class PosProductKdsRouting extends Model
{
    protected $table = 'POS.pos_product_kds_routing';
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'kds_station_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function kdsStation(): BelongsTo
    {
        return $this->belongsTo(PosKdsStation::class, 'kds_station_id');
    }
}
