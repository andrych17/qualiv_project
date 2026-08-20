<?php

namespace App\Modules\CustomFields\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. No update/delete at the app layer (CUSTOMFIELDS_SPECS.md §3H). */
class FieldDefAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'CUSTOMFIELDS.field_def_audit_logs';

    protected $fillable = [
        'field_def_id',
        'action',
        'actor_id',
        'before_snapshot',
        'after_snapshot',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
