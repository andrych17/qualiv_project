<?php

namespace App\Modules\Central\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class CentralPlan extends Model
{
    use CentralConnection;

    protected $fillable = [
        'code',
        'name',
        'description',
        'price_monthly',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CentralPlanModule::class, 'plan_code', 'code');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'ilike', '%'.$search.'%')
                    ->orWhere('name', 'ilike', '%'.$search.'%');
            });
        });
    }
}
