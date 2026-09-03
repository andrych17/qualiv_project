<?php

namespace App\Modules\PP\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * PP_SPECS.md §3E — a resource *type* no other Core module owns yet (tool, tank, utility,
 * warehouse-as-capacity). Machine/work-center identity stays in MES (not built), labor in HCM.
 */
class Resource extends Model
{
    protected $table = 'PP.pp_resources';

    public $timestamps = false;

    public const TYPE_TOOL = 'tool';

    public const TYPE_TANK = 'tank';

    public const TYPE_UTILITY = 'utility';

    public const TYPE_WAREHOUSE = 'warehouse';

    protected $fillable = [
        'type', 'code', 'name', 'capacity', 'uom_code', 'external_type', 'external_id', 'is_active',
    ];

    protected $casts = [
        'capacity' => 'decimal:4',
        'external_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('code', 'ilike', '%'.$search.'%')
                ->orWhere('name', 'ilike', '%'.$search.'%');
        })->when($filters['type'] ?? null, function ($query, $type) {
            $query->where('type', $type);
        });
    }
}
