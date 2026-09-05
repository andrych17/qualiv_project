<?php

namespace App\Modules\SysConfig\Models;

use Illuminate\Database\Eloquent\Model;

class TenantModule extends Model
{
    public const TOGGLEABLE = [
        'WNE', 'DMS', 'CRM', 'SCHEDULE', 'INVENTORY', 'ACCOUNTING',
        'PURCHASE', 'SALES', 'HCM', 'PAYROLL', 'PERFORMANCE', 'AIINSIGHT', 'LEGAL',
        'PROJECTS', 'MES', 'PP', 'POS',
    ];

    protected $table = 'SYSCONFIG.tenant_modules';

    protected $fillable = [
        'module_code',
        'is_active',
        'activated_at',
        'activated_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }
}
