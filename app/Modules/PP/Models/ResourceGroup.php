<?php

namespace App\Modules\PP\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** PP_SPECS.md §3E — lets a planner request capacity from a group ("20 machine-hours of MIXING") without picking a specific machine; the Detailed Scheduler (§3H) makes the specific assignment later. */
class ResourceGroup extends Model
{
    protected $table = 'PP.pp_resource_groups';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('code', 'ilike', '%'.$search.'%')
                ->orWhere('name', 'ilike', '%'.$search.'%');
        });
    }

    public function members()
    {
        return $this->hasMany(ResourceGroupMember::class, 'resource_group_id');
    }
}
