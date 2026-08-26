<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

class BankMaster extends Model
{
    protected $table = 'PAYROLL.bank_master';

    protected $fillable = [
        'code',
        'name',
        'file_format',
        'template_spec',
        'is_active',
    ];

    protected $casts = [
        'template_spec' => 'array',
        'is_active' => 'boolean',
    ];
}
