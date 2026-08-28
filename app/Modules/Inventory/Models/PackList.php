<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** §3P — one physical package (carton/pallet) built from PICKED lines of a single PickList. */
class PackList extends Model
{
    protected $table = 'INVENTORY.pack_lists';

    public const TYPE_CARTON = 'carton';

    public const TYPE_PALLET = 'pallet';

    public const STATUS_PACKED = 'packed';

    public const STATUS_SHIPPED = 'shipped';

    protected $fillable = [
        'warehouse_id', 'pick_list_id', 'shipment_id', 'package_type',
        'weight', 'weight_uom', 'length', 'width', 'height', 'dimension_uom',
        'status', 'packed_by', 'packed_at', 'created_by',
    ];

    protected $casts = [
        'weight' => 'decimal:4',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'packed_at' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function pickList()
    {
        return $this->belongsTo(PickList::class);
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function lines()
    {
        return $this->hasMany(PackListLine::class);
    }

    public function packedBy()
    {
        return $this->belongsTo(User::class, 'packed_by');
    }
}
