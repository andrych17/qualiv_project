<?php

namespace App\Modules\POS\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS_SPECS.md §3O / §4 — Kitchen Display System Station.
 */
class PosKdsStation extends Model
{
    protected $table = 'POS.pos_kds_stations';

    public $timestamps = false;

    protected $fillable = [
        'branch_id',
        'code',
        'name',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(PosBranch::class, 'branch_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'POS.pos_product_kds_routing',
            'kds_station_id',
            'product_id'
        );
    }

    public function txnLines(): HasMany
    {
        return $this->hasMany(PosTxnLine::class, 'kds_station_id');
    }
}
