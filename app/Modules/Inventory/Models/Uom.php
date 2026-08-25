<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Uom extends Model
{
    protected $table = 'INVENTORY.uoms';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'ilike', '%'.$search.'%')
                    ->orWhere('name', 'ilike', '%'.$search.'%');
            });
        })->when(($filters['status'] ?? null) !== null && $filters['status'] !== '', function ($query) use ($filters) {
            $query->where('is_active', $filters['status'] === 'active');
        });
    }
}
