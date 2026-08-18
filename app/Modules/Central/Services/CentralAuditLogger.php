<?php

namespace App\Modules\Central\Services;

use App\Modules\Central\Models\CentralAuditLog;
use Illuminate\Support\Facades\Auth;

/** Append-only writer for central_audit_logs (CENTRAL_SPECS.md §3I) — no update/delete path. */
class CentralAuditLogger
{
    public function log(
        string $action,
        string $entityType,
        string $entityId,
        array $before = [],
        array $after = [],
        ?string $actorId = null,
    ): void {
        $actorId ??= Auth::guard('central_admin')->id();

        CentralAuditLog::query()->create([
            'action' => $action,
            'actor_type' => $actorId ? 'central_admin' : 'system',
            'actor_id' => $actorId ? (string) $actorId : null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before ?: null,
            'after' => $after ?: null,
            'created_at' => now(),
        ]);
    }
}
