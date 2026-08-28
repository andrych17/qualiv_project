<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CycleCountLine extends Model
{
    protected $table = 'INVENTORY.cycle_count_lines';

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COUNTED = 'counted';

    protected $fillable = [
        'cycle_count_id', 'product_id', 'location_id', 'batch_id',
        'system_qty', 'counted_qty', 'status', 'counted_at', 'counted_by',
    ];

    protected $casts = [
        'system_qty' => 'decimal:4',
        'counted_qty' => 'decimal:4',
        'counted_at' => 'datetime',
    ];

    public function cycleCount()
    {
        return $this->belongsTo(CycleCount::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class);
    }

    public function countedBy()
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
