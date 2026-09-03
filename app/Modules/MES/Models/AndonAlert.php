<?php

namespace App\Modules\MES\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3R — alert-delivery bookkeeping (not the Andon state itself, which stays a pure read model). One open row per (alert_type, subject_type, subject_id), enforced by a partial unique index. */
class AndonAlert extends Model
{
    protected $table = 'MES.mes_andon_alerts';

    public $timestamps = false;

    public const TYPE_MACHINE_STOPPED = 'machine_stopped';

    public const TYPE_MAINTENANCE_REQUIRED = 'maintenance_required';

    public const TYPE_MATERIAL_SHORTAGE = 'material_shortage';

    public const TYPE_OUT_OF_SPEC_PARAMETER = 'out_of_spec_parameter';

    public const TYPE_BEHIND_SCHEDULE = 'behind_schedule';

    public const TYPE_OVERDUE_BATCH = 'overdue_batch';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = ['alert_type', 'subject_type', 'subject_id', 'severity', 'message', 'fired_at', 'resolved_at'];

    protected $casts = [
        'fired_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }
}
