<?php

namespace App\Modules\Config\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ConfigConst extends Model
{
    protected $table = 'SYSCONFIG.config_consts';

    protected $fillable = [
        'const_group',
        'group_code',
        'seq',
        'str1',
        'str2',
        'num1',
        'num2',
        'note1',
    ];

    protected function casts(): array
    {
        return [
            'num1' => 'decimal:4',
            'num2' => 'decimal:4',
        ];
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('const_group', 'ilike', '%'.$search.'%')
                    ->orWhere('group_code', 'ilike', '%'.$search.'%')
                    ->orWhere('str1', 'ilike', '%'.$search.'%')
                    ->orWhere('note1', 'ilike', '%'.$search.'%');
            });
        })->when($filters['const_group'] ?? null, function ($query, $group) {
            $query->where('const_group', $group);
        });
    }
}
