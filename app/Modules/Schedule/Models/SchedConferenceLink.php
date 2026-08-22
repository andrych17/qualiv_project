<?php

namespace App\Modules\Schedule\Models;

use Illuminate\Database\Eloquent\Model;

class SchedConferenceLink extends Model
{
    protected $table = 'SCHEDULE.sched_conference_links';

    public $timestamps = false;

    protected $fillable = ['sched_item_id', 'conference_provider_id', 'join_url', 'external_meeting_id', 'dial_in_info'];

    public function schedItem()
    {
        return $this->belongsTo(SchedItem::class, 'sched_item_id');
    }

    public function conferenceProvider()
    {
        return $this->belongsTo(ConferenceProvider::class, 'conference_provider_id');
    }
}
