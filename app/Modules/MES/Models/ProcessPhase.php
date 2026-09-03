<?php

namespace App\Modules\MES\Models;

use App\Modules\PP\Models\Recipe;
use Illuminate\Database\Eloquent\Model;

/**
 * MES_SPECS.md §3F — Routing's counterpart for continuous manufacturing: the execution-step
 * sequence, not the ingredient list (owned by PP's `pp_recipes`, §3B boundary note). No
 * MES-owned header table — rows sharing a `recipe_id` form one process's phase set, ordered by
 * `seq`, the same way `mes_routing_ops` rows are ordered under one `mes_routings` header, minus
 * the header row itself (the header already exists in PP).
 */
class ProcessPhase extends Model
{
    protected $table = 'MES.mes_process_phases';

    public $timestamps = false;

    protected $fillable = ['recipe_id', 'seq', 'phase_name', 'work_center_id', 'standard_duration_minutes'];

    public function recipe()
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }

    public function workCenter()
    {
        return $this->belongsTo(WorkCenter::class, 'work_center_id');
    }

    public function parameters()
    {
        return $this->hasMany(ProcessParameter::class, 'process_phase_id');
    }
}
