<?php

namespace App\Modules\HCM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgUnit extends Model
{
    protected $table = 'HCM.org_units';

    protected $fillable = [
        'parent_org_unit_id',
        'name',
        'accounting_cost_center_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'parent_org_unit_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(OrgUnit::class, 'parent_org_unit_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'org_unit_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where('name', 'ilike', '%'.$search.'%');
        })->when(isset($filters['is_active']) && $filters['is_active'] !== '', function ($query) use ($filters) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        });
    }
}
