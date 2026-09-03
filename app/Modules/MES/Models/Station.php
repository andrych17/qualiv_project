<?php

namespace App\Modules\MES\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3D — the physical spot an operator executes at (Shop Floor UI target, §3G). Hangs off a work center or a machine (or both), never neither. */
class Station extends Model
{
    protected $table = 'MES.mes_stations';

    public $timestamps = false;

    protected $fillable = ['work_center_id', 'machine_id', 'code', 'name'];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('code', 'ilike', '%'.$search.'%')
                ->orWhere('name', 'ilike', '%'.$search.'%');
        });
    }

    public function workCenter()
    {
        return $this->belongsTo(WorkCenter::class, 'work_center_id');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }
}
