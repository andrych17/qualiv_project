<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class LocationBarcode extends Model
{
    protected $table = 'INVENTORY.location_barcodes';

    public $timestamps = false;

    protected $fillable = ['location_id', 'barcode'];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
