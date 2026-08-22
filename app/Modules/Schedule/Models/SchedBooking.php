<?php

namespace App\Modules\Schedule\Models;

use Illuminate\Database\Eloquent\Model;

class SchedBooking extends Model
{
    protected $table = 'SCHEDULE.sched_bookings';

    public $timestamps = false;

    protected $fillable = ['sched_item_id', 'resource_id'];

    public function schedItem()
    {
        return $this->belongsTo(SchedItem::class, 'sched_item_id');
    }

    public function resource()
    {
        return $this->belongsTo(Resource::class, 'resource_id');
    }
}
