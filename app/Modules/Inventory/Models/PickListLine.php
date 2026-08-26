<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PickListLine extends Model
{
    protected $table = 'INVENTORY.pick_list_lines';

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PICKED = 'picked';

    protected $fillable = [
        'pick_list_id', 'reservation_id', 'product_id', 'batch_id', 'serial_id', 'location_id',
        'qty', 'confirmed_qty', 'status', 'picked_at', 'picked_by',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'confirmed_qty' => 'decimal:4',
        'picked_at' => 'datetime',
    ];

    public function pickList()
    {
        return $this->belongsTo(PickList::class);
    }

    public function reservation()
    {
        return $this->belongsTo(StockReservation::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class);
    }

    public function serial()
    {
        return $this->belongsTo(StockSerial::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function pickedBy()
    {
        return $this->belongsTo(User::class, 'picked_by');
    }
}
