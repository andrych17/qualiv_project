<?php

namespace App\Modules\Schedule\Models;

use Illuminate\Database\Eloquent\Model;

class SchedWorkingHour extends Model
{
    protected $table = 'SCHEDULE.sched_working_hours';

    public $timestamps = false;

    protected $fillable = ['resource_id', 'day_of_week', 'start_time', 'end_time'];

    public function resource()
    {
        return $this->belongsTo(Resource::class, 'resource_id');
    }
}
