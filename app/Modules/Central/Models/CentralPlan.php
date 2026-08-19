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
        'price_annual',
        'billing_cycle',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_annual' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** The price the invoice engine should use for this plan's billing cycle (CENTRAL_SPECS.md §3E). */
    public function unitPrice(): float
    {
        return (float) ($this->billing_cycle === 'annual'
            ? ($this->price_annual ?? $this->price_monthly)
            : $this->price_monthly);
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
