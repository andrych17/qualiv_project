<?php

namespace App\Modules\MES\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * MES_SPECS.md §3M — one planned or unplanned downtime span against a machine or (when no
 * single machine is the cause) a bare work center — same either-or-both ownership rule as
 * `Station` (`chk_mes_downtime_events_owner`). `notified_at` is this build's own idempotency
 * guard for the auto-maintenance-request threshold sweep, not part of the spec's column list.
 */
class DowntimeEvent extends Model
{
    protected $table = 'MES.mes_downtime_events';

    public $timestamps = false;

    public const CATEGORY_PLANNED = 'planned';

    public const CATEGORY_UNPLANNED = 'unplanned';

    public const REASON_MAINTENANCE = 'maintenance';

    public const REASON_SETUP = 'setup';

    public const REASON_MECHANICAL = 'mechanical';

    public const REASON_ELECTRICAL = 'electrical';

    public const REASON_MATERIAL_SHORTAGE = 'material_shortage';

    public const REASON_QUALITY = 'quality';

    public const REASON_OPERATOR = 'operator';

    /** @var list<string> valid reason_code values when category = planned */
    public const PLANNED_REASONS = [self::REASON_MAINTENANCE, self::REASON_SETUP];

    /** @var list<string> valid reason_code values when category = unplanned */
    public const UNPLANNED_REASONS = [
        self::REASON_MECHANICAL, self::REASON_ELECTRICAL, self::REASON_MATERIAL_SHORTAGE,
        self::REASON_QUALITY, self::REASON_OPERATOR,
    ];

    protected $fillable = [
        'machine_id', 'work_center_id', 'order_id', 'category', 'reason_code',
        'started_at', 'ended_at', 'notified_at', 'started_by', 'ended_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['status'] ?? null, function ($query, $status) {
            $status === 'open' ? $query->whereNull('ended_at') : $query->whereNotNull('ended_at');
        })->when($filters['category'] ?? null, function ($query, $category) {
            $query->where('category', $category);
        })->when($filters['machine_id'] ?? null, function ($query, $machineId) {
            $query->where('machine_id', $machineId);
        })->when($filters['work_center_id'] ?? null, function ($query, $workCenterId) {
            $query->where('work_center_id', $workCenterId);
        });
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function workCenter()
    {
        return $this->belongsTo(WorkCenter::class, 'work_center_id');
    }

    public function order()
    {
        return $this->belongsTo(ProdOrder::class, 'order_id');
    }

    public function startedBy()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
