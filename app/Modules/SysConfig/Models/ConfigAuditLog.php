<?php

namespace App\Modules\SysConfig\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. No update/delete at the app layer (SYSCONFIG_SPECS.md §3G). */
class ConfigAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'SYSCONFIG.config_audit_logs';

    protected $fillable = [
        'table_name',
        'record_id',
        'action',
        'actor_id',
        'before_value',
        'after_value',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_value' => 'array',
            'after_value' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
