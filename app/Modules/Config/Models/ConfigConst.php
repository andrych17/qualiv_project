<?php

namespace App\Modules\Config\Models;

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
}
