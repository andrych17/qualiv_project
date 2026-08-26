<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** §3O — generated from one or more active reservations, grouped one-per-warehouse. */
class PickList extends Model
{
    protected $table = 'INVENTORY.pick_lists';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_READY_FOR_PACKING = 'ready_for_packing';

    protected $fillable = ['warehouse_id', 'status', 'assigned_to', 'created_by', 'completed_at'];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function lines()
    {
        return $this->hasMany(PickListLine::class);
    }
}
