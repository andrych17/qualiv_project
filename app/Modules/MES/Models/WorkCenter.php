<?php

namespace App\Modules\MES\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** MES_SPECS.md §3D — Plant → Area/Line → Work Center → Machine → Station hierarchy, top level. */
class WorkCenter extends Model
{
    protected $table = 'MES.mes_work_centers';

    public const TYPE_DISCRETE = 'discrete';

    public const TYPE_PROCESS = 'process';

    protected $fillable = ['code', 'name', 'area_line', 'type'];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('code', 'ilike', '%'.$search.'%')
                ->orWhere('name', 'ilike', '%'.$search.'%');
        })->when($filters['type'] ?? null, function ($query, $type) {
            $query->where('type', $type);
        });
    }

    public function machines()
    {
        return $this->hasMany(Machine::class, 'work_center_id');
    }
}
