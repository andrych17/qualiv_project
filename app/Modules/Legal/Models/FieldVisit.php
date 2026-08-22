<?php

namespace App\Modules\Legal\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FieldVisit extends Model
{
    protected $table = 'LEGAL.field_visits';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [self::STATUS_SCHEDULED, self::STATUS_CHECKED_IN, self::STATUS_COMPLETED];

    protected $fillable = [
        'matter_id', 'land_object_id', 'deed_id', 'visit_type_id', 'assigned_to',
        'schedule_item_id', 'status', 'checked_in_at', 'gps_lat', 'gps_lng',
        'checklist_result', 'notes',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'gps_lat' => 'decimal:7',
        'gps_lng' => 'decimal:7',
        'checklist_result' => 'array',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }

    public function matter()
    {
        return $this->belongsTo(Matter::class);
    }

    public function landObject()
    {
        return $this->belongsTo(LandObject::class);
    }

    public function deed()
    {
        return $this->belongsTo(Deed::class);
    }

    public function visitType()
    {
        return $this->belongsTo(FieldVisitType::class, 'visit_type_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
