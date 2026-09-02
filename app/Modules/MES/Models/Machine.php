<?php

namespace App\Modules\MES\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3D — a machine within a work center; `status` drives the Shop Floor UI's equipment strip. */
class Machine extends Model
{
    protected $table = 'MES.mes_machines';

    public const STATUS_RUNNING = 'running';

    public const STATUS_IDLE = 'idle';

    public const STATUS_DOWN = 'down';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_SETUP = 'setup';

    public const STATUS_WAITING_MATERIAL = 'waiting_material';

    public const STATUS_WAITING_OPERATOR = 'waiting_operator';

    public const STATUS_WAITING_QC = 'waiting_qc';

    protected $fillable = ['work_center_id', 'code', 'name', 'status'];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('code', 'ilike', '%'.$search.'%')
                ->orWhere('name', 'ilike', '%'.$search.'%');
        })->when($filters['work_center_id'] ?? null, function ($query, $workCenterId) {
            $query->where('work_center_id', $workCenterId);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }

    public function workCenter()
    {
        return $this->belongsTo(WorkCenter::class, 'work_center_id');
    }
}
