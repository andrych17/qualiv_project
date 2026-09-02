<?php

namespace App\Modules\MES\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MES_SPECS.md §3M — append-only status history for one machine; `mes_machines.status` is the
 * denormalized "current" read, rebuildable from this log, same `stock_balances`-from-
 * `stock_ledger` cache pattern Inventory uses. Written exclusively by `DowntimeService`.
 */
class EquipmentStatusLog extends Model
{
    protected $table = 'MES.mes_equipment_status_logs';

    public $timestamps = false;

    protected $fillable = ['machine_id', 'status', 'started_at', 'ended_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }
}
