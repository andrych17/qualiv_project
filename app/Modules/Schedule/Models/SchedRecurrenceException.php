<?php

namespace App\Modules\Schedule\Models;

use Illuminate\Database\Eloquent\Model;

class SchedRecurrenceException extends Model
{
    protected $table = 'SCHEDULE.sched_recurrence_exceptions';

    public $timestamps = false;

    public const ACTION_SKIPPED = 'skipped';

    public const ACTION_MOVED = 'moved';

    public const ACTION_MODIFIED = 'modified';

    protected $fillable = ['sched_item_id', 'original_occurrence_date', 'action', 'override_start_at', 'override_end_at'];

    protected $casts = [
        'original_occurrence_date' => 'date',
        'override_start_at' => 'datetime',
        'override_end_at' => 'datetime',
    ];

    public function schedItem()
    {
        return $this->belongsTo(SchedItem::class, 'sched_item_id');
    }
}
