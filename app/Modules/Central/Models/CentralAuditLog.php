<?php

namespace App\Modules\Central\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Append-only (CENTRAL_SPECS.md §3I) — no update/delete permitted at the app layer.
 * Only ever written to via CentralAuditLogger::log().
 */
class CentralAuditLog extends Model
{
    use CentralConnection;

    public $timestamps = false;

    protected $fillable = [
        'action',
        'actor_type',
        'actor_id',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
