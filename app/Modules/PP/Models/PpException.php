<?php

namespace App\Modules\PP\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** PP_SPECS.md §3M — one row per detected planning condition; written by other engines' constraint checks, never by hand. */
class PpException extends Model
{
    protected $table = 'PP.pp_exceptions';

    /** No created_at/updated_at — `detected_at` (DB-defaulted `now()`) and `resolved_at` are the only timestamps. */
    public $timestamps = false;

    public const TYPE_MATERIAL_SHORTAGE = 'material_shortage';

    public const TYPE_CAPACITY_OVERLOAD = 'capacity_overload';

    public const TYPE_LATE_ORDER = 'late_order';

    public const TYPE_MISSING_ROUTING = 'missing_routing';

    public const TYPE_RESOURCE_UNAVAILABLE = 'resource_unavailable';

    public const TYPE_MAINTENANCE_CONFLICT = 'maintenance_conflict';

    public const TYPE_LATE_PURCHASE = 'late_purchase';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    /** subject_type values this table's rows currently ever carry — §3M's own generation is scoped to engines that exist. */
    public const SUBJECT_PLANNED_ORDER = 'pp.pp_planned_orders';

    public const SUBJECT_CAPACITY_PLAN = 'pp.pp_capacity_plans';

    public const SUBJECT_MPS_LINE = 'pp.pp_mps_lines';

    protected $fillable = [
        'exception_type', 'severity', 'subject_type', 'subject_id',
        'detail', 'detected_at', 'status', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['status'] ?? null, function ($query, $status) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        })->when($filters['exception_type'] ?? null, function ($query, $type) {
            $query->where('exception_type', $type);
        });
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
