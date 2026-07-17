<?php

namespace App\Modules\Config\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ConfigSnum extends Model
{
    protected $table = 'SYSCONFIG.config_snums';

    protected $fillable = [
        'code',
        'last_cnt',
        'wrap_low',
        'wrap_high',
        'step_cnt',
        'descr',
        'status_code',
    ];

    protected function casts(): array
    {
        return [
            'last_cnt' => 'integer',
            'wrap_low' => 'integer',
            'wrap_high' => 'integer',
            'step_cnt' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status_code', 'A');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'ilike', '%'.$search.'%')
                    ->orWhere('descr', 'ilike', '%'.$search.'%');
            });
        });
    }
}
