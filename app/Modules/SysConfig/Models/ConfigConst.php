<?php

namespace App\Modules\SysConfig\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ConfigConst extends Model
{
    protected $table = 'SYSCONFIG.config_consts';

    protected $fillable = [
        'appl_id',
        'group_id',
        'user_id',
        'const_group',
        'group_code',
        'value',
        'value_type',
        'seq',
        'str1',
        'str2',
        'num1',
        'num2',
        'note1',
        'effective_date',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'num1' => 'decimal:4',
            'num2' => 'decimal:4',
            'is_active' => 'boolean',
            'effective_date' => 'date',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('const_group', 'ilike', '%'.$search.'%')
                    ->orWhere('group_code', 'ilike', '%'.$search.'%')
                    ->orWhere('str1', 'ilike', '%'.$search.'%')
                    ->orWhere('value', 'ilike', '%'.$search.'%')
                    ->orWhere('note1', 'ilike', '%'.$search.'%');
            });
        })->when($filters['const_group'] ?? null, function ($query, $group) {
            $query->where('const_group', $group);
        })->when(($filters['show_inactive'] ?? null) !== '1', function ($query) {
            $query->where('is_active', true);
        });
    }
}
