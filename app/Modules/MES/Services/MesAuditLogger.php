<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\MesAuditLog;

/**
 * MES_SPECS.md §3U — the one write path for `mes_audit_logs`, same shape as
 * `SysConfig\Services\ConfigAuditLogger`. Distinct from `ProdEventService` (§3C): that's the
 * business action stream; this is the change-history stream for edits to already-recorded data.
 */
class MesAuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(string $subjectType, int $subjectId, string $action, ?array $before, ?array $after, int $actorId): void
    {
        MesAuditLog::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'actor_id' => $actorId,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'created_at' => now(),
        ]);
    }
}
