<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** §3Q — scoped exactly one way: a location, a category, or an ABC class. */
class CycleCount extends Model
{
    protected $table = 'INVENTORY.cycle_counts';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'warehouse_id', 'location_id', 'category_id', 'abc_class',
        'status', 'assigned_to', 'scheduled_date', 'completed_at', 'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function lines()
    {
        return $this->hasMany(CycleCountLine::class);
    }
}
