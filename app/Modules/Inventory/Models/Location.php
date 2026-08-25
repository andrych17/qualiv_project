<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $table = 'INVENTORY.locations';

    public const TYPE_ZONE = 'zone';

    public const TYPE_BIN = 'bin';

    public const TYPE_STAGING = 'staging';

    public const TYPE_DOCK = 'dock';

    protected $fillable = ['warehouse_id', 'parent_location_id', 'code', 'type', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_location_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_location_id');
    }

    public function barcodes()
    {
        return $this->hasMany(LocationBarcode::class);
    }
}
