<?php

namespace App\Modules\Schedule\Models;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    protected $table = 'SCHEDULE.resources';

    public $timestamps = false;

    protected $fillable = ['resource_type_id', 'name', 'location_notes', 'capacity', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function resourceType()
    {
        return $this->belongsTo(ResourceType::class, 'resource_type_id');
    }

    public function bookings()
    {
        return $this->hasMany(SchedBooking::class, 'resource_id');
    }

    public function workingHours()
    {
        return $this->hasMany(SchedWorkingHour::class, 'resource_id')->orderBy('day_of_week');
    }
}
