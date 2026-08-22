<?php

namespace App\Modules\Schedule\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SchedAttendee extends Model
{
    protected $table = 'SCHEDULE.sched_attendees';

    public $timestamps = false;

    public const ROLE_ATTENDEE = 'attendee';

    public const ROLE_WATCHER = 'watcher';

    protected $fillable = ['sched_item_id', 'user_id', 'role'];

    public function schedItem()
    {
        return $this->belongsTo(SchedItem::class, 'sched_item_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
