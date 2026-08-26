<?php

namespace App\Modules\HCM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RegionalMinimumWage extends Model
{
    protected $table = 'HCM.regional_minimum_wages';

    public $timestamps = false;

    protected $fillable = [
        'region_code',
        'region_name',
        'effective_date',
        'monthly_wage_amount',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'monthly_wage_amount' => 'decimal:2',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($q) use ($search) {
                $q->where('region_code', 'ilike', '%'.$search.'%')
                    ->orWhere('region_name', 'ilike', '%'.$search.'%');
            });
        });
    }
}
