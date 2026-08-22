<?php

namespace App\Modules\Schedule\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SchedItem extends Model
{
    protected $table = 'SCHEDULE.sched_items';

    public const TYPE_TASK = 'task';

    public const TYPE_EVENT = 'event';

    public const TASK_STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    public const EVENT_STATUSES = ['scheduled', 'cancelled'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $fillable = [
        'uuid', 'type', 'title', 'description', 'owner_id', 'subject_type', 'subject_id',
        'recurrence_rule', 'status', 'priority', 'due_at', 'start_at', 'end_at', 'all_day', 'location',
    ];

    protected $casts = [
        'all_day' => 'boolean',
        'due_at' => 'datetime',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function attendees()
    {
        return $this->hasMany(SchedAttendee::class, 'sched_item_id');
    }

    public function bookings()
    {
        return $this->hasMany(SchedBooking::class, 'sched_item_id');
    }

    public function recurrenceExceptions()
    {
        return $this->hasMany(SchedRecurrenceException::class, 'sched_item_id');
    }

    public function conferenceLink()
    {
        return $this->hasOne(SchedConferenceLink::class, 'sched_item_id');
    }

    public function scopeTasks($query)
    {
        return $query->where('type', self::TYPE_TASK);
    }

    public function scopeEvents($query)
    {
        return $query->where('type', self::TYPE_EVENT);
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('title', 'ilike', '%'.$search.'%');
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        })->when($filters['priority'] ?? null, function ($query, $priority) {
            $query->where('priority', $priority);
        })->when($filters['owner_id'] ?? null, function ($query, $ownerId) {
            $query->where('owner_id', $ownerId);
        });
    }
}
